/**
 * Test: satellite state persists when switching from Tracker → Catalog → Tracker.
 * Uses a module-level SatelliteTracker mock to capture props across remounts.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, act } from '@testing-library/react';

vi.mock('jspdf', ()=>({jsPDF:vi.fn(()=>({save:vi.fn(),text:vi.fn(),setFontSize:vi.fn(),setFont:vi.fn(),addPage:vi.fn()}))}));
vi.mock('react-ga4', ()=>({default:{initialize:vi.fn(),send:vi.fn()}}));
vi.mock('../contexts/ToastContext', ()=>({
  ToastProvider:({children})=>children,
  useToast:()=>({success:vi.fn(),error:vi.fn()}),
}));
vi.mock('../contexts/CookieConsentContext', ()=>({
  CookieConsentProvider:({children})=>children,
  useCookieConsent:()=>({
    consent:null,showBanner:false,showSettings:false,
    acceptAll:vi.fn(),rejectNonEssential:vi.fn(),saveCustom:vi.fn(),
    openSettings:vi.fn(),closeSettings:vi.fn(),
  }),
}));
vi.mock('../components/CookieBanner', ()=>({default:()=>null}));
vi.mock('../components/Footer',       ()=>({default:()=>null}));
vi.mock('../contexts/AuthContext', ()=>({
  AuthProvider:({children})=>children,
  useAuth:()=>({user:null,loading:false,logout:vi.fn()}),
}));
vi.mock('../DebrisMonitor',      ()=>({default:()=><div data-testid="debris-monitor"/>}));
vi.mock('../ConjunctionAlerts',  ()=>({default:()=><div data-testid="conjunction-alerts"/>}));

// Capture each render's props so we can assert on them after remounts.
let trackerRenders = [];
vi.mock('../satellite-tracker', ()=>({
  default: (props) => {
    trackerRenders.push(props);
    return <div data-testid="satellite-tracker"/>;
  },
}));

import App from '../App';

describe('View-switch state persistence', ()=>{

  beforeEach(()=>{
    trackerRenders = [];
    vi.clearAllMocks();
  });

  it('satellite A added to tracker is still present when switching back from catalog', async ()=>{
    render(<App/>);

    // Switch to tracker — first mount
    // NavBar renders each label twice (desktop tab + mobile menu item); click the first (desktop).
    fireEvent.click(screen.getAllByText('TRACKER')[0]);
    expect(screen.getByTestId('satellite-tracker')).toBeInTheDocument();

    const firstProps = trackerRenders[trackerRenders.length - 1];
    expect(firstProps).toHaveProperty('onSatelliteAdded');
    expect(firstProps.savedSats).toEqual([]);

    // Simulate the tracker adding satellite A (ISS)
    act(()=>{
      firstProps.onSatelliteAdded('25544', 'ISS (ZARYA)');
    });

    // Switch to CATALOG — tracker unmounts
    fireEvent.click(screen.getAllByText('CATALOG')[0]);
    expect(screen.getByTestId('debris-monitor')).toBeInTheDocument();

    // Switch back to TRACKER — tracker remounts
    fireEvent.click(screen.getAllByText('TRACKER')[0]);
    expect(screen.getByTestId('satellite-tracker')).toBeInTheDocument();

    // The remounted tracker must receive ISS in savedSats
    const remountProps = trackerRenders[trackerRenders.length - 1];
    expect(remountProps.savedSats).toEqual(
      expect.arrayContaining([
        expect.objectContaining({id:'25544', name:'ISS (ZARYA)'}),
      ]),
    );
  });
});
