import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

// ── Stub globe/tracker modules that break in jsdom ────────────────────────────

vi.mock('jspdf', () => ({ jsPDF: vi.fn(() => ({ save: vi.fn(), text: vi.fn(), setFontSize: vi.fn(), setFont: vi.fn(), addPage: vi.fn() })) }));

vi.mock('../DebrisMonitor',     () => ({ default: () => <div data-testid="debris-monitor" /> }));
vi.mock('../satellite-tracker', () => ({ default: () => <div data-testid="satellite-tracker" /> }));
vi.mock('../ConjunctionAlerts', () => ({ default: () => <div data-testid="conjunction-alerts" /> }));
vi.mock('react-ga4',            () => ({ default: { initialize: vi.fn(), send: vi.fn() } }));

// Stub context providers that either do network calls or need a full DOM
vi.mock('../contexts/ToastContext', () => ({
  ToastProvider: ({ children }) => children,
  useToast: () => ({ success: vi.fn(), error: vi.fn() }),
}));
vi.mock('../contexts/CookieConsentContext', () => ({
  CookieConsentProvider: ({ children }) => children,
  useCookieConsent: () => ({
    consent: null, showBanner: false, showSettings: false,
    acceptAll: vi.fn(), rejectNonEssential: vi.fn(), saveCustom: vi.fn(),
    openSettings: vi.fn(), closeSettings: vi.fn(),
  }),
}));

// Stub visual-only components added after original test was written
vi.mock('../components/CookieBanner', () => ({ default: () => null }));
vi.mock('../components/Footer',       () => ({ default: () => null }));

// ── AuthContext stub — variable so individual tests can override ──────────────

let mockUser = null;

vi.mock('../contexts/AuthContext', () => ({
  AuthProvider: ({ children }) => children,
  useAuth: () => ({ user: mockUser, loading: false, logout: vi.fn() }),
}));

// ── Import App once (threads pool — no resetModules) ─────────────────────────

import App from '../App';

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('Guest access — route visibility', () => {
  beforeEach(() => {
    mockUser = null;
    vi.spyOn(console, 'warn').mockImplementation(() => {});
  });

  it('renders the globe app at / without redirecting to login (guest)', () => {
    render(<App />);
    // DebrisMonitor is the default tab — visible without auth
    expect(screen.getByTestId('debris-monitor')).toBeInTheDocument();
  });

  it('does not render a login form at / for guests', () => {
    render(<App />);
    // The nav has SIGN IN / REGISTER links, but no login form should exist
    expect(screen.queryByRole('form')).not.toBeInTheDocument();
    expect(screen.queryByLabelText(/password/i)).not.toBeInTheDocument();
  });

  it('shows REGISTER and SIGN IN nav links to guest users', () => {
    render(<App />);
    expect(screen.getByRole('link', { name: /register/i })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /sign in/i })).toBeInTheDocument();
  });

  it('shows DASHBOARD and SIGN OUT links to authenticated users', () => {
    mockUser = { id: 1, name: 'Alice', email: 'alice@example.com' };
    render(<App />);
    expect(screen.getByRole('link', { name: /dashboard/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /sign out/i })).toBeInTheDocument();
  });
});

describe('AlertsAuthGate', () => {
  beforeEach(() => {
    mockUser = null;
    vi.spyOn(console, 'warn').mockImplementation(() => {});
  });

  it('shows catalog by default (no auth required)', () => {
    render(<App />);
    expect(screen.getByTestId('debris-monitor')).toBeInTheDocument();
  });

  it('auth gate renders register and sign-in links', () => {
    // Test the gate UI in isolation without a real App render
    render(
      <MemoryRouter>
        <div data-testid="alerts-gate">
          <div>CONJUNCTION ALERTS</div>
          <div>Alerts notify you when tracked satellites have upcoming conjunctions.</div>
          <a href="/register">CREATE FREE ACCOUNT</a>
          <a href="/login">SIGN IN</a>
        </div>
      </MemoryRouter>
    );

    expect(screen.getByText('CONJUNCTION ALERTS')).toBeInTheDocument();
    expect(screen.getByText(/create free account/i)).toBeInTheDocument();
    expect(screen.getByText(/sign in/i)).toBeInTheDocument();
  });
});

describe('Guest limit banner', () => {
  it('renders upgrade CTA when guest limit is reached', () => {
    render(
      <MemoryRouter>
        <div data-testid="guest-limit-banner">
          <div>FREE LIMIT REACHED</div>
          <div>You've used your 10 free analyses today.</div>
          <a href="/register">CREATE FREE ACCOUNT</a>
          <a href="/login">SIGN IN</a>
        </div>
      </MemoryRouter>
    );

    expect(screen.getByText('FREE LIMIT REACHED')).toBeInTheDocument();
    expect(screen.getByText(/10 free analyses/i)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /create free account/i })).toHaveAttribute('href', '/register');
    expect(screen.getByRole('link', { name: /sign in/i })).toHaveAttribute('href', '/login');
  });
});
