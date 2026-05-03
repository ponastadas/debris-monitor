/**
 * Tests for tracker search input Enter-key behaviour and multi-satellite conjunction fetching.
 *
 * Tests:
 *  1. Enter with text before debounce fires → triggers search → loads first result
 *  2. Enter with pure numeric NORAD ID → loads directly, no search
 *  3. Enter with text, search returns empty → shows "No satellite found"
 *  4. Add A then B → conjunction fetch fires for both
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

// ── THREE.js stub ─────────────────────────────────────────────────────────────
// Uses regular function constructors so `new THREE.X()` works in jsdom.
vi.mock('three', () => {
  function V3(x=0,y=0,z=0){this.x=x;this.y=y;this.z=z;}
  V3.prototype.set=function(){return this;};
  V3.prototype.copy=function(v){this.x=v.x;this.y=v.y;this.z=v.z;return this;};
  V3.prototype.clone=function(){return new V3(this.x,this.y,this.z);};
  V3.prototype.normalize=function(){return this;};
  V3.prototype.multiplyScalar=function(){return this;};
  V3.prototype.add=function(){return this;};
  V3.prototype.sub=function(){return this;};
  V3.prototype.distanceTo=function(){return 0.5;};
  V3.prototype.lengthSq=function(){return 1;};
  V3.prototype.applyMatrix3=function(){return this;};

  function makeGeo(){return {dispose:vi.fn(),setFromPoints:vi.fn().mockReturnThis(),setAttribute:vi.fn()};}
  function makeMat(){return {dispose:vi.fn()};}
  function makeObj(){return {
    position:new V3(),scale:new V3(1,1,1),rotation:{x:0,y:0,z:0},
    material:makeMat(),geometry:makeGeo(),lookAt:vi.fn(),add:vi.fn(),remove:vi.fn(),
    children:[],dispose:vi.fn(),
  };}

  // canvas element for WebGLRenderer.domElement (only used as a DOM node to appendChild)
  const canvas = Object.assign(document.createElement('canvas'), { style: {} });

  function Scene()    { Object.assign(this, makeObj()); this.background = null; }
  function Renderer() { Object.assign(this, { setSize:vi.fn(), setPixelRatio:vi.fn(), setClearColor:vi.fn(), render:vi.fn(), dispose:vi.fn(), domElement:canvas }); }
  function Camera()   { Object.assign(this, makeObj()); this.aspect=1; this.updateProjectionMatrix=vi.fn(); }
  function Geo()      { Object.assign(this, makeGeo()); }
  function Mat()      { Object.assign(this, makeMat()); }
  function Obj()      { Object.assign(this, makeObj()); }
  function ColorCtor(){ Object.assign(this, {}); }
  function TexLoader(){ this.load=vi.fn((_,cb)=>cb&&cb({dispose:vi.fn()})); }
  function CanvasTex(){ this.dispose=vi.fn(); }
  function BufAttr()  {}

  return {
    Scene:             vi.fn(function(){ Scene.call(this); }),
    WebGLRenderer:     vi.fn(function(){ Renderer.call(this); }),
    PerspectiveCamera: vi.fn(function(){ Camera.call(this); }),
    Vector3:           V3,
    Color:             vi.fn(function(){ ColorCtor.call(this); }),
    SphereGeometry:    vi.fn(function(){ Geo.call(this); }),
    RingGeometry:      vi.fn(function(){ Geo.call(this); }),
    BufferGeometry:    vi.fn(function(){ Geo.call(this); }),
    MeshBasicMaterial: vi.fn(function(){ Mat.call(this); }),
    LineBasicMaterial: vi.fn(function(){ Mat.call(this); }),
    SpriteMaterial:    vi.fn(function(){ Mat.call(this); }),
    PointsMaterial:    vi.fn(function(){ Mat.call(this); }),
    Mesh:              vi.fn(function(){ Obj.call(this); }),
    Line:              vi.fn(function(){ Obj.call(this); }),
    Sprite:            vi.fn(function(){ Obj.call(this); }),
    Points:            vi.fn(function(){ Obj.call(this); }),
    AmbientLight:      vi.fn(function(){ Obj.call(this); }),
    CanvasTexture:     vi.fn(function(){ CanvasTex.call(this); }),
    TextureLoader:     vi.fn(function(){ TexLoader.call(this); }),
    BufferAttribute:        vi.fn(function(){ BufAttr.call(this); }),
    Float32BufferAttribute: vi.fn(function(){ BufAttr.call(this); }),
    DirectionalLight:       vi.fn(function(){ Obj.call(this); }),
    MeshPhongMaterial:      vi.fn(function(){ Mat.call(this); }),
    DoubleSide:2, BackSide:1, FrontSide:0, AdditiveBlending:2, NormalBlending:1,
  };
});

// ── satellite.js stub ─────────────────────────────────────────────────────────
vi.mock('satellite.js', () => ({
  twoline2satrec: vi.fn(()=>({satnum:'25544'})),
  propagate:      vi.fn(()=>({position:{x:4000,y:0,z:5500},velocity:{x:7,y:0.1,z:0.1}})),
  gstime:         vi.fn(()=>1.0),
  eciToGeodetic:  vi.fn(()=>({latitude:0.5,longitude:0.5,height:400})),
  degreesLat:     vi.fn(r=>r*57.3),
  degreesLong:    vi.fn(r=>r*57.3),
}));

// ── API client mock ───────────────────────────────────────────────────────────
vi.mock('../api/client', ()=>({default:{get:vi.fn(),post:vi.fn()}}));

import { beforeAll } from 'vitest';
import SatelliteTracker from '../satellite-tracker';
import client from '../api/client';

// createSatelliteTexture calls canvas.getContext('2d') which jsdom doesn't implement.
// Provide a minimal stub so addSatellite doesn't throw.
beforeAll(() => {
  const ctx2d = {
    createRadialGradient: vi.fn(() => ({ addColorStop: vi.fn() })),
    fillRect:  vi.fn(), beginPath: vi.fn(), arc: vi.fn(),
    fill: vi.fn(), stroke: vi.fn(), moveTo: vi.fn(), lineTo: vi.fn(),
  };
  Object.defineProperty(HTMLCanvasElement.prototype, 'getContext', {
    configurable: true,
    value: vi.fn(() => ctx2d),
  });
});

// ── Fixtures ──────────────────────────────────────────────────────────────────
const TLE_OK = (noradId='25544', name='ISS (ZARYA)') => ({
  data:{data:{name,norad_id:noradId,
    tle_line1:'1 25544U 98067A   24001.00000000  .00000000  00000-0  00000-0 0  9999',
    tle_line2:'2 25544  51.6400   0.0000 0001000   0.0000   0.0000 15.50000000000000',
  }},
});
const SEARCH_OK = (results=[]) => ({data:{data:results}});
const CONJ_OK   = (noradId='25544') => ({
  data:{data:{source:'simulated',objects:[{
    object_id:`CDM-${noradId}-1`,secondary_norad_id:'99001',
    miss_km:3.5,probability:0.0001,risk_score:45,risk_level:'MEDIUM',
    tca:'2026-04-22',altitude_km:420,
  }]}},
  headers:{},
});

function wrap(props={}) {
  return render(
    <MemoryRouter><SatelliteTracker {...props}/></MemoryRouter>
  );
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('Enter key — tracker search input', ()=>{

  beforeEach(()=>{
    vi.clearAllMocks();
    client.get.mockImplementation(url=>{
      if(url.includes('/satellites/search'))  return Promise.resolve(SEARCH_OK());
      if(url.includes('/conjunctions/'))       return Promise.resolve(CONJ_OK());
      if(url.match(/\/satellites\/\d+/))       return Promise.resolve(TLE_OK());
      return Promise.reject(new Error('unmatched: '+url));
    });
  });

  it('Enter with text before debounce fires: triggers immediate search and loads first result', async ()=>{
    client.get.mockImplementation(url=>{
      if(url.includes('/satellites/search'))
        return Promise.resolve(SEARCH_OK([{name:'ISS (ZARYA)',norad_id:'25544'}]));
      if(url.includes('/conjunctions/'))   return Promise.resolve(CONJ_OK());
      if(url.match(/\/satellites\/\d+/))   return Promise.resolve(TLE_OK());
      return Promise.reject(new Error('unmatched'));
    });

    wrap({ initialNoradId:'' });
    const input = screen.getByPlaceholderText('Name or NORAD ID…');

    // Type text — do NOT wait for the 400 ms debounce
    fireEvent.change(input, { target: { value: 'ISS' } });
    // Press Enter immediately (debounce hasn't fired yet)
    fireEvent.keyDown(input, { key: 'Enter', code: 'Enter' });

    // Search must have been triggered
    await waitFor(()=>{
      expect(client.get).toHaveBeenCalledWith(
        '/satellites/search',
        expect.objectContaining({params:{q:'ISS'}}),
      );
    });
    // TLE fetch follows for the first result
    await waitFor(()=>{
      expect(client.get).toHaveBeenCalledWith('/satellites/25544');
    });
  });

  it('Enter with a numeric value loads the satellite directly without searching', async ()=>{
    wrap({ initialNoradId:'' });
    const input = screen.getByPlaceholderText('Name or NORAD ID…');

    fireEvent.change(input, { target: { value: '25544' } });
    fireEvent.keyDown(input, { key: 'Enter', code: 'Enter' });

    await waitFor(()=>{
      expect(client.get).toHaveBeenCalledWith('/satellites/25544');
    });
    expect(client.get).not.toHaveBeenCalledWith(
      '/satellites/search', expect.anything(),
    );
  });

  it('Enter with text when search returns empty shows "No satellite found"', async ()=>{
    client.get.mockImplementation(url=>{
      if(url.includes('/satellites/search'))  return Promise.resolve(SEARCH_OK([]));
      if(url.match(/\/satellites\/\d+/))      return Promise.resolve(TLE_OK());
      if(url.includes('/conjunctions/'))       return Promise.resolve(CONJ_OK());
      return Promise.reject(new Error('unmatched'));
    });

    wrap({ initialNoradId:'' });
    const input = screen.getByPlaceholderText('Name or NORAD ID…');

    fireEvent.change(input, { target: { value: 'XYZNOTFOUND' } });
    fireEvent.keyDown(input, { key: 'Enter', code: 'Enter' });

    await waitFor(()=>{
      expect(screen.getByText(/No satellite found/i)).toBeInTheDocument();
    });
  });
});

describe('Conjunction fetch — multi-satellite', ()=>{

  beforeEach(()=>{
    vi.clearAllMocks();
    client.get.mockImplementation(url=>{
      if(url.includes('/satellites/search'))  return Promise.resolve(SEARCH_OK());
      if(url.includes('/conjunctions/'))       return Promise.resolve(CONJ_OK());
      if(url.match(/\/satellites\/\d+/))       return Promise.resolve(TLE_OK());
      return Promise.reject(new Error('unmatched: '+url));
    });
  });

  it('adds two satellites and fires a separate conjunction fetch for each', async ()=>{
    const onAdded = vi.fn();

    wrap({ initialNoradId:'25544', onSatelliteAdded:onAdded });

    // Wait for satellite A (ISS) to be tracked
    await waitFor(()=>expect(onAdded).toHaveBeenCalledWith('25544',expect.any(String)));

    // Conjunction fetch fired for satellite A
    expect(client.get).toHaveBeenCalledWith('/conjunctions/25544');

    // Reconfigure client to serve satellite B (Hubble)
    client.get.mockImplementation(url=>{
      if(url.includes('/satellites/search'))
        return Promise.resolve(SEARCH_OK([{name:'HST',norad_id:'20580'}]));
      if(url==='/satellites/20580')        return Promise.resolve(TLE_OK('20580','HST'));
      if(url.includes('/conjunctions/'))   return Promise.resolve(CONJ_OK('20580'));
      if(url.match(/\/satellites\/\d+/))   return Promise.resolve(TLE_OK());
      return Promise.reject(new Error('unmatched'));
    });

    // Add satellite B via the search input + Enter
    const input = screen.getByPlaceholderText('Name or NORAD ID…');
    fireEvent.change(input, { target: { value: 'Hubble' } });
    fireEvent.keyDown(input, { key: 'Enter', code: 'Enter' });

    await waitFor(()=>expect(client.get).toHaveBeenCalledWith('/conjunctions/20580'));
    expect(onAdded).toHaveBeenCalledWith('20580',expect.any(String));
  });
});

describe('loadAndTrack error handling', ()=>{

  beforeEach(()=>{
    vi.clearAllMocks();
    // Default: initial load succeeds (avoid unrelated 25544 errors)
    client.get.mockImplementation(url=>{
      if(url.includes('/conjunctions/'))  return Promise.resolve(CONJ_OK());
      if(url.match(/\/satellites\/\d+/))  return Promise.resolve(TLE_OK());
      return Promise.reject(new Error('unmatched: '+url));
    });
  });

  it('shows backend error message from {success,data} envelope when satellite cannot be loaded', async ()=>{
    // client.js interceptor normalizes all errors to plain {type, code, message} objects.
    // The 404 from the backend becomes: {type:'SERVER_ERROR', code:'NOT_FOUND', message:'...'}
    const apiError = { type: 'SERVER_ERROR', code: 'NOT_FOUND', message: 'Satellite 46913 not found' };

    client.get.mockImplementation(url=>{
      if(url.includes('/satellites/search'))
        return Promise.resolve(SEARCH_OK([{name:'R2',norad_id:'46913'}]));
      if(url==='/satellites/46913') return Promise.reject(apiError);
      if(url.includes('/conjunctions/'))  return Promise.resolve(CONJ_OK());
      if(url.match(/\/satellites\/\d+/))  return Promise.resolve(TLE_OK());
      return Promise.reject(new Error('unmatched'));
    });

    wrap({ initialNoradId:'' });
    const input = screen.getByPlaceholderText('Name or NORAD ID…');
    fireEvent.change(input, { target: { value: 'r2' } });

    // Debounce fires; wait for results
    await waitFor(()=>expect(client.get).toHaveBeenCalledWith(
      '/satellites/search', expect.objectContaining({params:{q:'r2'}}),
    ));

    // Click the first search result
    const item = await screen.findByText('R2');
    fireEvent.click(item.closest('.search-dropdown-item'));

    // Backend error message from the envelope should be shown, not the generic fallback
    await waitFor(()=>{
      expect(screen.getByText(/Satellite 46913 not found/i)).toBeInTheDocument();
    });
  });

  it('shows generic fallback error when interceptor error has no message', async ()=>{
    // An error object with no message property (e.g. unexpected throw)
    const networkError = {};

    client.get.mockImplementation(url=>{
      if(url.includes('/satellites/search'))
        return Promise.resolve(SEARCH_OK([{name:'R2',norad_id:'46913'}]));
      if(url==='/satellites/46913') return Promise.reject(networkError);
      if(url.includes('/conjunctions/'))  return Promise.resolve(CONJ_OK());
      if(url.match(/\/satellites\/\d+/))  return Promise.resolve(TLE_OK());
      return Promise.reject(new Error('unmatched'));
    });

    wrap({ initialNoradId:'' });
    const input = screen.getByPlaceholderText('Name or NORAD ID…');
    fireEvent.change(input, { target: { value: 'r2' } });

    await waitFor(()=>expect(client.get).toHaveBeenCalledWith(
      '/satellites/search', expect.objectContaining({params:{q:'r2'}}),
    ));

    const item = await screen.findByText('R2');
    fireEvent.click(item.closest('.search-dropdown-item'));

    await waitFor(()=>{
      expect(screen.getByText(/Could not load satellite 46913/i)).toBeInTheDocument();
    });
  });

  it('shows guest limit CTA when guest quota is exhausted while loading a satellite', async ()=>{
    // client.js interceptor normalizes 429 GUEST_LIMIT_REACHED to a plain object
    const guestLimitError = {
      type: 'GUEST_LIMIT_REACHED',
      code: 'GUEST_LIMIT_REACHED',
      message: "You've used your 10 free analyses today. Create a free account to continue.",
    };

    client.get.mockImplementation(url=>{
      if(url.includes('/satellites/search'))
        return Promise.resolve(SEARCH_OK([{name:'R2',norad_id:'46913'}]));
      if(url==='/satellites/46913') return Promise.reject(guestLimitError);
      if(url.includes('/conjunctions/'))  return Promise.resolve(CONJ_OK());
      if(url.match(/\/satellites\/\d+/))  return Promise.resolve(TLE_OK());
      return Promise.reject(new Error('unmatched'));
    });

    wrap({ initialNoradId:'' });
    const input = screen.getByPlaceholderText('Name or NORAD ID…');
    fireEvent.change(input, { target: { value: 'r2' } });

    await waitFor(()=>expect(client.get).toHaveBeenCalledWith(
      '/satellites/search', expect.objectContaining({params:{q:'r2'}}),
    ));

    const item = await screen.findByText('R2');
    fireEvent.click(item.closest('.search-dropdown-item'));

    // Should show the upgrade CTA banner, not an error message
    await waitFor(()=>{
      expect(screen.getByText(/FREE LIMIT REACHED/i)).toBeInTheDocument();
    });
    // Should NOT show the generic "could not load" error
    expect(screen.queryByText(/Could not load satellite/i)).not.toBeInTheDocument();
  });
});
