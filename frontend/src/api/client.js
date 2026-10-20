export async function apiFetch(url, options = {}) {
    const token = localStorage.getItem('p8p_token');

    const headers = {
        'Content-Type': 'application/json',
        ...(options.headers || {}),
        ...(token ? { 'Authorization': `Bearer ${token}` } : {})
    };

    const response = await fetch(url, { ...options, headers });

    // Si el backend responde 401 Unauthorized, expira la sesión local
    if (response.status === 401) {
        localStorage.removeItem('p8p_token');
        localStorage.removeItem('p8p_user');
        window.location.reload();
        throw new Error('Sesión expirada o no autorizada');
    }

    return response.json();
}
