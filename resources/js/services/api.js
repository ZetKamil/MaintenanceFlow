function getCookie(name) {
    const match = document.cookie.match(new RegExp('(^|;\\s*)(' + name + ')=([^;]*)'));
    return match ? decodeURIComponent(match[3]) : null;
}

export class ApiError extends Error {
    constructor(message, status, errors = {}) {
        super(message);
        this.status = status;
        this.errors = errors;
    }
}

export async function apiRequest(url, options = {}) {
    const method = (options.method || 'GET').toUpperCase();
    const headers = {
        Accept: 'application/json',
        ...options.headers,
    };

    if (options.body && !(options.body instanceof FormData)) {
        headers['Content-Type'] = 'application/json';
    }

    // Attach fresh CSRF protection for all mutating same-origin requests
    if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
        const xsrfCookie = getCookie('XSRF-TOKEN');
        if (xsrfCookie) {
            headers['X-XSRF-TOKEN'] = xsrfCookie;
        } else {
            const metaToken = document.querySelector('meta[name="csrf-token"]')?.content;
            if (metaToken) {
                headers['X-CSRF-TOKEN'] = metaToken;
            }
        }
    }

    const response = await fetch(url, {
        ...options,
        method,
        headers,
        credentials: 'same-origin',
        body: options.body && !(options.body instanceof FormData)
            ? JSON.stringify(options.body)
            : options.body,
    });

    const isJson = response.headers.get('content-type')?.includes('application/json');
    const data = isJson ? await response.json() : null;

    if (!response.ok) {
        throw new ApiError(
            data?.message || `Request failed with status ${response.status}`,
            response.status,
            data?.errors || {}
        );
    }

    return data;
}

export const api = {
    get: (url) => apiRequest(url, { method: 'GET' }),
    post: (url, body) => apiRequest(url, { method: 'POST', body }),
    put: (url, body) => apiRequest(url, { method: 'PUT', body }),
    delete: (url) => apiRequest(url, { method: 'DELETE' }),
};