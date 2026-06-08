import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type {
  ContractDetail,
  ContractRequirement,
  ContractSummary,
  Customer,
} from '@/types';

const KEY = ['customers'] as const;

export function useCustomers() {
  return useQuery<Customer[]>({
    queryKey: [...KEY, 'list'],
    queryFn: async () => (await api.get<Customer[]>('/customers')).data,
    staleTime: 60_000,
  });
}

export function useContracts() {
  return useQuery<ContractSummary[]>({
    queryKey: [...KEY, 'contracts'],
    queryFn: async () => (await api.get<ContractSummary[]>('/customers/contracts')).data,
    staleTime: 60_000,
  });
}

export function useContract(id: string | number | undefined) {
  return useQuery<ContractDetail>({
    queryKey: [...KEY, 'contract', String(id)],
    enabled: id != null,
    queryFn: async () => (await api.get<ContractDetail>(`/customers/contracts/${id}`)).data,
  });
}

export function useCreateCustomer() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: Partial<Customer>) =>
      (await api.post<Customer>('/customers', payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useUpdateCustomer() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, payload }: { id: number; payload: Partial<Customer> }) =>
      (await api.patch<Customer>(`/customers/${id}`, payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useCreateContract() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: Partial<ContractSummary> & { customer_id: number }) =>
      (await api.post<ContractDetail>('/customers/contracts', payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useUpdateContract() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, payload }: { id: number; payload: Partial<ContractSummary> }) =>
      (await api.patch<ContractDetail>(`/customers/contracts/${id}`, payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useAddContractRequirement(contractId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: Partial<ContractRequirement>) =>
      (await api.post<ContractRequirement>(`/customers/contracts/${contractId}/requirements`, payload))
        .data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useUpdateContractRequirement() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, payload }: { id: number; payload: Partial<ContractRequirement> }) =>
      (await api.patch<ContractRequirement>(`/customers/requirements/${id}`, payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useDeleteContractRequirement() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/customers/requirements/${id}`);
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}
