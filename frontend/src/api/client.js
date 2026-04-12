import axios from 'axios';

const client = axios.create({
  baseURL: '/api',
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
  withCredentials: false, // bearer token auth, not cookies
});

// Attach auth headers on every request
client.interceptors.request.use((config) => {
  const token = localStorage.getItem('dm_token');
  if (token) {
    // Authenticated user — send bearer token
    config.headers.Authorization = `Bearer ${token}`;
  } else {
    // Anonymous guest — send guest ID for quota tracking (X-Guest-ID → HandlePublicRequest)
    const guestId = localStorage.getItem('dm_guest_id');
    if (guestId) {
      config.headers['X-Guest-ID'] = guestId;
    }
  }
  return config;
});

// Normalize all error responses to a consistent shape
client.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status;
    const body   = error.response?.data;

    if (status === 401) {
      localStorage.removeItem('dm_token');
      // Avoid redirect loop on the login page itself
      if (!window.location.pathname.startsWith('/login')) {
        window.location.href = '/login';
      }
      return Promise.reject({ type: 'UNAUTHENTICATED', message: 'Session expired. Please log in again.' });
    }

    if (status === 403) {
      return Promise.reject({
        type: 'FORBIDDEN',
        code: body?.error?.code ?? 'FORBIDDEN',
        message: body?.error?.message ?? 'You do not have permission to do this.',
      });
    }

    if (status === 422) {
      return Promise.reject({
        type: 'VALIDATION',
        code: body?.error?.code ?? 'VALIDATION_ERROR',
        message: body?.error?.message ?? 'Validation failed.',
        details: body?.error?.details ?? {},
      });
    }

    if (status === 429) {
      const code = body?.error?.code;

      if (code === 'GUEST_LIMIT_REACHED') {
        return Promise.reject({
          type: 'GUEST_LIMIT_REACHED',
          code: 'GUEST_LIMIT_REACHED',
          message: body.error.message,
          details: body.error.details ?? {},
        });
      }

      return Promise.reject({
        type: 'RATE_LIMIT',
        code: code ?? 'RATE_LIMITED',
        message: body?.error?.message ?? 'Too many requests. Please wait a moment and try again.',
        details: body?.error?.details ?? {},
      });
    }

    return Promise.reject({
      type: 'SERVER_ERROR',
      code: body?.error?.code ?? 'SERVER_ERROR',
      message: body?.error?.message ?? 'An unexpected error occurred.',
    });
  }
);

export default client;
