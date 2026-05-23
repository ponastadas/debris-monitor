import '@testing-library/jest-dom'

// jsdom does not implement ResizeObserver; stub it so components that use it
// (e.g. SatelliteTracker's canvas resize handler) do not throw.
if (typeof ResizeObserver === 'undefined') {
  globalThis.ResizeObserver = class ResizeObserver {
    observe()    {}
    unobserve()  {}
    disconnect() {}
  };
}
