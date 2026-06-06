import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

export interface MyOpenItem {
  type: string;
  id: number;
  number: string;
  title: string;
  status: string;
  due_date: string | null;
  overdue: boolean;
  url: string;
}

export function useMyOpenItems() {
  return useQuery<MyOpenItem[]>({
    queryKey: ['dashboard', 'my-open-items'],
    queryFn: async () => {
      const { data } = await api.get<MyOpenItem[]>('/dashboard/my-open-items');
      return data;
    },
    staleTime: 60_000,
  });
}
