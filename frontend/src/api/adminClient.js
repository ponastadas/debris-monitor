import axios from 'axios';

const adminClient = axios.create({
  baseURL: '/api',
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
  withCredentials: false,
});

// Attach admin bearer token on every request
adminClient.interceptors.request.use((config) => {
  const token = localStorage.getItem('dm_admin_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Normalize error responses
adminClient.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status;
    const body   = error.response?.data;

    if (status === 401) {
      localStorage.removeItem('dm_admin_token');
      if (!window.location.pathname.startsWith('/admin/login')) {
        window.location.href = '/admin/login';
      }
      return Promise.reject({ type: 'UNAUTHENTICATED', message: 'Admin session expired. Please log in again.' });
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
      return Promise.reject({
        type: 'RATE_LIMIT',
        code: body?.error?.code ?? 'RATE_LIMITED',
        message: body?.error?.message ?? 'Too many requests. Please wait a moment and try again.',
      });
    }

    return Promise.reject({
      type: 'SERVER_ERROR',
      code: body?.error?.code ?? 'SERVER_ERROR',
      message: body?.error?.message ?? 'An unexpected error occurred.',
    });
  }
);

export default adminClient;
