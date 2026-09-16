import { createSlice, createAsyncThunk } from '@reduxjs/toolkit';
import { login as loginRequest, fetchCurrentUser, logout as logoutRequest, switchActiveRole } from '../../services/authService';

const storedUser = localStorage.getItem('auth_user');

const initialState = {
  user: storedUser ? JSON.parse(storedUser) : null,
  token: localStorage.getItem('auth_token') || null,
  status: 'idle', // idle | loading | succeeded | failed
  error: null,
};

export const loginUser = createAsyncThunk('auth/login', async ({ email, password }, { rejectWithValue }) => {
  try {
    return await loginRequest(email, password);
  } catch (error) {
    return rejectWithValue(
      error.response?.data?.errors?.email?.[0] || error.response?.data?.message || 'Login failed.'
    );
  }
});

export const loadCurrentUser = createAsyncThunk('auth/loadCurrentUser', async (_, { rejectWithValue }) => {
  try {
    return await fetchCurrentUser();
  } catch (error) {
    return rejectWithValue(error.response?.data?.message || 'Session expired.');
  }
});

export const logoutUser = createAsyncThunk('auth/logout', async () => {
  await logoutRequest().catch(() => {});
});

export const switchRole = createAsyncThunk('auth/switchRole', async (role, { rejectWithValue }) => {
  try {
    return await switchActiveRole(role);
  } catch (error) {
    return rejectWithValue(error.response?.data?.message || 'Could not switch role.');
  }
});

const authSlice = createSlice({
  name: 'auth',
  initialState,
  reducers: {},
  extraReducers: (builder) => {
    builder
      .addCase(loginUser.pending, (state) => {
        state.status = 'loading';
        state.error = null;
      })
      .addCase(loginUser.fulfilled, (state, action) => {
        state.status = 'succeeded';
        state.user = action.payload.user;
        state.token = action.payload.token;
        localStorage.setItem('auth_token', action.payload.token);
        localStorage.setItem('auth_user', JSON.stringify(action.payload.user));
      })
      .addCase(loginUser.rejected, (state, action) => {
        state.status = 'failed';
        state.error = action.payload;
      })
      .addCase(loadCurrentUser.fulfilled, (state, action) => {
        state.user = action.payload;
        localStorage.setItem('auth_user', JSON.stringify(action.payload));
      })
      .addCase(loadCurrentUser.rejected, (state) => {
        state.user = null;
        state.token = null;
      })
      .addCase(logoutUser.fulfilled, (state) => {
        state.user = null;
        state.token = null;
        localStorage.removeItem('auth_token');
        localStorage.removeItem('auth_user');
      })
      .addCase(switchRole.fulfilled, (state, action) => {
        state.user = action.payload;
        localStorage.setItem('auth_user', JSON.stringify(action.payload));
      });
  },
});

export default authSlice.reducer;