import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import './index.css';
import App from './App.jsx';

// GA4 is NOT initialized here unconditionally.
// CookieConsentContext initializes it lazily when analytics consent is given.
// This ensures analytics cookies are never set before user consent.

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <App />
  </StrictMode>,
);
