import apiClient from './apiClient';

export async function login(email, password) {
  const { data } = await apiClient.post('/auth/login', { email, password });
  return data; // { token, user }
}

export async function fetchCurrentUser() {
  const { data } = await apiClient.get('/auth/me');
  return data.user;
}

export async function logout() {
  await apiClient.post('/auth/logout');
}

export async function switchActiveRole(role) {
  const { data } = await apiClient.patch('/auth/active-role', { role });
  return data.user;
}

export async function pingApi() {
  const { data } = await apiClient.get('/ping');
  return data;
}