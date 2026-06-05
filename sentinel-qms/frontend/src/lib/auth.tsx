import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react';
import { api, tokenStore } from './api';
import type { LoginRequest, Role, TokenResponse, User } from '@/types';

/**
 * Shape returned by the backend `/auth/me` (UserRead). Roles arrive as objects
 * carrying display names ("Quality Manager"); the frontend RBAC layer works in
 * slug form ("quality_manager"), so we normalize on the way in. The backend
 * keys users by email and has no separate username column.
 */
interface RawUser {
  id: number | string;
  email: string;
  full_name: string;
  department?: string | null;
  is_active: boolean;
  last_login_at?: string | null;
  created_at?: string | null;
  roles?: Array<{ name: string } | string>;
}

const roleSlug = (name: string): Role =>
  name.trim().toLowerCase().replace(/[\s-]+/g, '_') as Role;

function mapUser(raw: RawUser): User {
  const roles = (raw.roles ?? []).map((r) =>
    typeof r === 'string' ? roleSlug(r) : roleSlug(r.name),
  );
  return {
    id: String(raw.id),
    username: raw.email,
    email: raw.email,
    full_name: raw.full_name,
    roles,
    department: raw.department ?? undefined,
    is_active: raw.is_active,
    last_login_at: raw.last_login_at ?? undefined,
    created_at: raw.created_at ?? '',
  };
}

async function fetchProfile(): Promise<User> {
  const { data } = await api.get<RawUser>('/auth/me');
  return mapUser(data);
}

interface AuthState {
  user: User | null;
  loading: boolean;
  isAuthenticated: boolean;
  login: (credentials: LoginRequest) => Promise<void>;
  logout: () => void;
  hasRole: (...roles: Role[]) => boolean;
  /** Re-authenticate the current user (used by electronic signatures). */
  reauthenticate: (password: string) => Promise<boolean>;
}

const AuthContext = createContext<AuthState | undefined>(undefined);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);

  const loadProfile = useCallback(async () => {
    if (!tokenStore.access) {
      setUser(null);
      setLoading(false);
      return;
    }
    try {
      const profile = await fetchProfile();
      setUser(profile);
    } catch {
      tokenStore.clear();
      setUser(null);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadProfile();
  }, [loadProfile]);

  // Handle transparent-refresh failures from the axios interceptor.
  useEffect(() => {
    const onExpired = () => {
      tokenStore.clear();
      setUser(null);
    };
    window.addEventListener('sentinel:session-expired', onExpired);
    return () => window.removeEventListener('sentinel:session-expired', onExpired);
  }, []);

  const login = useCallback(async (credentials: LoginRequest) => {
    const { data } = await api.post<TokenResponse>('/auth/login', credentials);
    tokenStore.set(data);
    const profile = await fetchProfile();
    setUser(profile);
  }, []);

  const logout = useCallback(() => {
    tokenStore.clear();
    setUser(null);
  }, []);

  const hasRole = useCallback(
    (...roles: Role[]) => {
      if (!user) return false;
      if (roles.length === 0) return true;
      return roles.some((r) => user.roles.includes(r));
    },
    [user],
  );

  const reauthenticate = useCallback(
    async (password: string): Promise<boolean> => {
      if (!user) return false;
      try {
        await api.post('/auth/login', { username: user.email, password });
        return true;
      } catch {
        return false;
      }
    },
    [user],
  );

  const value = useMemo<AuthState>(
    () => ({
      user,
      loading,
      isAuthenticated: Boolean(user),
      login,
      logout,
      hasRole,
      reauthenticate,
    }),
    [user, loading, login, logout, hasRole, reauthenticate],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth(): AuthState {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within an AuthProvider');
  return ctx;
}
