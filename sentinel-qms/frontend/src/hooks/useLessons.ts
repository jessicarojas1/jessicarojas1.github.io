import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { LessonLearned } from '@/types';

const KEY = ['lessons-learned'] as const;

export function useLessons() {
  return useQuery<LessonLearned[]>({
    queryKey: [...KEY, 'list'],
    queryFn: async () => (await api.get<LessonLearned[]>('/lessons-learned')).data,
    staleTime: 60_000,
  });
}

export function useCreateLesson() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: Partial<LessonLearned>) =>
      (await api.post<LessonLearned>('/lessons-learned', payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useUpdateLesson(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: Partial<LessonLearned>) =>
      (await api.patch<LessonLearned>(`/lessons-learned/${id}`, payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}
