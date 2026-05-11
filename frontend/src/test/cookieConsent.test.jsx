import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, act, fireEvent } from '@testing-library/react';
import { CookieConsentProvider, useCookieConsent } from '../contexts/CookieConsentContext';

// ── GA4 mock ──────────────────────────────────────────────────────────────────
// We mock react-ga4 so dynamic import() in CookieConsentContext is intercepted.

const mockInitialize = vi.fn();

vi.mock('react-ga4', () => ({
  default: { initialize: mockInitialize, send: vi.fn() },
}));

// ── localStorage helpers ──────────────────────────────────────────────────────

const CONSENT_KEY = 'dm_cookie_consent';

function clearConsent() {
  localStorage.removeItem(CONSENT_KEY);
}

function writeConsent(overrides = {}) {
  const record = {
    v: 1,
    necessary: true,
    analytics: false,
    marketing: false,
    ts: new Date().toISOString(),
    ...overrides,
  };
  localStorage.setItem(CONSENT_KEY, JSON.stringify(record));
  return record;
}

function readConsent() {
  const raw = localStorage.getItem(CONSENT_KEY);
  return raw ? JSON.parse(raw) : null;
}

// ── Test consumer component ───────────────────────────────────────────────────

function ConsentConsumer({ onMount }) {
  const ctx = useCookieConsent();
  // Call onMount with context so tests can inspect and call methods
  if (onMount) onMount(ctx);
  return (
    <div>
      <span data-testid="analytics">{String(ctx.consent?.analytics ?? 'null')}</span>
      <span data-testid="marketing">{String(ctx.consent?.marketing ?? 'null')}</span>
      <span data-testid="show-banner">{String(ctx.showBanner)}</span>
      <span data-testid="show-settings">{String(ctx.showSettings)}</span>
      <button onClick={ctx.acceptAll}>Accept All</button>
      <button onClick={ctx.rejectNonEssential}>Reject</button>
      <button onClick={ctx.openSettings}>Open Settings</button>
      <button onClick={ctx.closeSettings}>Close Settings</button>
      <button onClick={() => ctx.saveCustom({ analytics: true, marketing: false })}>
        Save Custom
      </button>
    </div>
  );
}

function renderConsent(onMount) {
  return render(
    <CookieConsentProvider>
      <ConsentConsumer onMount={onMount} />
    </CookieConsentProvider>
  );
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('Consent defaults', () => {
  beforeEach(() => {
    clearConsent();
    mockInitialize.mockClear();
  });

  it('shows the banner when no prior consent exists', () => {
    renderConsent();
    expect(screen.getByTestId('show-banner').textContent).toBe('true');
  });

  it('does not show the settings modal by default', () => {
    renderConsent();
    expect(screen.getByTestId('show-settings').textContent).toBe('false');
  });

  it('consent is null before any choice is made', () => {
    renderConsent();
    expect(screen.getByTestId('analytics').textContent).toBe('null');
    expect(screen.getByTestId('marketing').textContent).toBe('null');
  });

  it('does not show the banner when consent already exists in localStorage', () => {
    writeConsent({ analytics: false });
    renderConsent();
    expect(screen.getByTestId('show-banner').textContent).toBe('false');
  });

  it('treats a stored consent with a different version as if absent', () => {
    localStorage.setItem(CONSENT_KEY, JSON.stringify({ v: 99, necessary: true, analytics: true }));
    renderConsent();
    // Old-version record ignored → banner should appear
    expect(screen.getByTestId('show-banner').textContent).toBe('true');
    expect(screen.getByTestId('analytics').textContent).toBe('null');
  });

  it('treats malformed JSON in localStorage as absent', () => {
    localStorage.setItem(CONSENT_KEY, 'not-valid-json');
    renderConsent();
    expect(screen.getByTestId('show-banner').textContent).toBe('true');
  });
});

describe('Accept all', () => {
  beforeEach(() => {
    clearConsent();
    mockInitialize.mockClear();
  });

  it('sets analytics and marketing to true', async () => {
    renderConsent();
    await act(async () => {
      fireEvent.click(screen.getByText('Accept All'));
    });
    expect(screen.getByTestId('analytics').textContent).toBe('true');
    expect(screen.getByTestId('marketing').textContent).toBe('true');
  });

  it('hides the banner after accepting all', async () => {
    renderConsent();
    await act(async () => {
      fireEvent.click(screen.getByText('Accept All'));
    });
    expect(screen.getByTestId('show-banner').textContent).toBe('false');
  });

  it('persists the consent record to localStorage', async () => {
    renderConsent();
    await act(async () => {
      fireEvent.click(screen.getByText('Accept All'));
    });
    const stored = readConsent();
    expect(stored).not.toBeNull();
    expect(stored.v).toBe(1);
    expect(stored.necessary).toBe(true);
    expect(stored.analytics).toBe(true);
    expect(stored.marketing).toBe(true);
    expect(stored.ts).toBeTruthy();
  });
});

describe('Reject non-essential', () => {
  beforeEach(() => {
    clearConsent();
    mockInitialize.mockClear();
  });

  it('sets analytics and marketing to false', async () => {
    renderConsent();
    await act(async () => {
      fireEvent.click(screen.getByText('Reject'));
    });
    expect(screen.getByTestId('analytics').textContent).toBe('false');
    expect(screen.getByTestId('marketing').textContent).toBe('false');
  });

  it('hides the banner after rejecting', async () => {
    renderConsent();
    await act(async () => {
      fireEvent.click(screen.getByText('Reject'));
    });
    expect(screen.getByTestId('show-banner').textContent).toBe('false');
  });

  it('persists necessary=true even when all else is rejected', async () => {
    renderConsent();
    await act(async () => {
      fireEvent.click(screen.getByText('Reject'));
    });
    const stored = readConsent();
    expect(stored.necessary).toBe(true);
    expect(stored.analytics).toBe(false);
    expect(stored.marketing).toBe(false);
  });
});

describe('Save custom preferences', () => {
  beforeEach(() => {
    clearConsent();
    mockInitialize.mockClear();
  });

  it('saves the provided analytics choice', async () => {
    renderConsent();
    // "Save Custom" button sends { analytics: true, marketing: false }
    await act(async () => {
      fireEvent.click(screen.getByText('Save Custom'));
    });
    expect(screen.getByTestId('analytics').textContent).toBe('true');
    expect(screen.getByTestId('marketing').textContent).toBe('false');
  });

  it('persists custom prefs to localStorage', async () => {
    renderConsent();
    await act(async () => {
      fireEvent.click(screen.getByText('Save Custom'));
    });
    const stored = readConsent();
    expect(stored.analytics).toBe(true);
    expect(stored.marketing).toBe(false);
  });

  it('hides the banner after saving custom prefs', async () => {
    renderConsent();
    await act(async () => {
      fireEvent.click(screen.getByText('Save Custom'));
    });
    expect(screen.getByTestId('show-banner').textContent).toBe('false');
  });
});

describe('Settings modal flow', () => {
  beforeEach(() => {
    clearConsent();
    mockInitialize.mockClear();
  });

  it('openSettings hides the banner and shows the settings modal', async () => {
    renderConsent();
    await act(async () => {
      fireEvent.click(screen.getByText('Open Settings'));
    });
    expect(screen.getByTestId('show-banner').textContent).toBe('false');
    expect(screen.getByTestId('show-settings').textContent).toBe('true');
  });

  it('closeSettings hides the settings modal', async () => {
    renderConsent();
    await act(async () => {
      fireEvent.click(screen.getByText('Open Settings'));
    });
    await act(async () => {
      fireEvent.click(screen.getByText('Close Settings'));
    });
    expect(screen.getByTestId('show-settings').textContent).toBe('false');
  });

  it('closeSettings restores the banner if no consent has been saved', async () => {
    // No consent in storage → closing without choosing should re-show banner
    renderConsent();
    await act(async () => {
      fireEvent.click(screen.getByText('Open Settings'));
    });
    await act(async () => {
      fireEvent.click(screen.getByText('Close Settings'));
    });
    expect(screen.getByTestId('show-banner').textContent).toBe('true');
  });

  it('closeSettings does NOT restore the banner if consent was already saved', async () => {
    writeConsent({ analytics: false }); // pre-existing consent
    renderConsent();

    // Banner should already be hidden
    expect(screen.getByTestId('show-banner').textContent).toBe('false');

    await act(async () => {
      fireEvent.click(screen.getByText('Open Settings'));
    });
    await act(async () => {
      fireEvent.click(screen.getByText('Close Settings'));
    });
    // Banner stays hidden — consent already recorded
    expect(screen.getByTestId('show-banner').textContent).toBe('false');
  });
});

describe('Analytics gate (GA4 init)', () => {
  beforeEach(() => {
    clearConsent();
    mockInitialize.mockClear();
  });

  afterEach(() => {
    vi.unstubAllEnvs();
  });

  it('does not call GA4 initialize before any consent is given', () => {
    vi.stubEnv('VITE_GA_MEASUREMENT_ID', 'G-TEST123');
    renderConsent();
    expect(mockInitialize).not.toHaveBeenCalled();
  });

  it('does not call GA4 initialize when analytics is rejected', async () => {
    vi.stubEnv('VITE_GA_MEASUREMENT_ID', 'G-TEST123');
    renderConsent();
    await act(async () => {
      fireEvent.click(screen.getByText('Reject'));
    });
    expect(mockInitialize).not.toHaveBeenCalled();
  });

  it('calls GA4 initialize after analytics consent is accepted', async () => {
    vi.stubEnv('VITE_GA_MEASUREMENT_ID', 'G-TEST123');
    renderConsent();
    await act(async () => {
      fireEvent.click(screen.getByText('Accept All'));
    });
    // CookieConsentContext uses dynamic import — give it a microtask flush
    await act(async () => {});
    expect(mockInitialize).toHaveBeenCalledWith('G-TEST123');
  });

  it('calls GA4 initialize when custom prefs include analytics=true', async () => {
    vi.stubEnv('VITE_GA_MEASUREMENT_ID', 'G-TEST123');
    renderConsent();
    await act(async () => {
      fireEvent.click(screen.getByText('Save Custom')); // analytics=true
    });
    await act(async () => {});
    expect(mockInitialize).toHaveBeenCalledWith('G-TEST123');
  });

  it('does not call GA4 initialize if VITE_GA_MEASUREMENT_ID is not set', async () => {
    vi.stubEnv('VITE_GA_MEASUREMENT_ID', '');
    renderConsent();
    await act(async () => {
      fireEvent.click(screen.getByText('Accept All'));
    });
    await act(async () => {});
    expect(mockInitialize).not.toHaveBeenCalled();
  });

  it('initializes GA4 immediately on mount if prior analytics consent exists', async () => {
    vi.stubEnv('VITE_GA_MEASUREMENT_ID', 'G-TEST123');
    writeConsent({ analytics: true });
    renderConsent();
    await act(async () => {});
    expect(mockInitialize).toHaveBeenCalledWith('G-TEST123');
  });

  it('does not initialize GA4 on mount if prior consent has analytics=false', async () => {
    vi.stubEnv('VITE_GA_MEASUREMENT_ID', 'G-TEST123');
    writeConsent({ analytics: false });
    renderConsent();
    await act(async () => {});
    expect(mockInitialize).not.toHaveBeenCalled();
  });
});

describe('useCookieConsent guard', () => {
  it('throws when used outside CookieConsentProvider', () => {
    // Suppress the error boundary output
    const spy = vi.spyOn(console, 'error').mockImplementation(() => {});

    expect(() => render(<ConsentConsumer />)).toThrow(
      'useCookieConsent must be inside <CookieConsentProvider>'
    );

    spy.mockRestore();
  });
});
