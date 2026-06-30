import { normalizeNullableId, type NullableOption, type Option, type ProjectFilters } from '@/components/admin/projects/project-shared';
import { computed, watch } from 'vue';

type ProjectCreateStateProps = {
    currentOrganisation?: NullableOption;
    filters: ProjectFilters;
    organisations: Option[];
};

type ProjectCreateForm = {
    name: string;
    client_id: number | null;
    organisation_id: number | null;
    isActive: boolean;
    processing: boolean;
    errors: Record<string, string>;
    post: (
        url: string,
        options?: {
            preserveScroll?: boolean;
            onSuccess?: () => void;
            onError?: () => void;
        },
    ) => void;
    reset: () => void;
};

export function omitErrors(errors: Record<string, string>, keys: string[]) {
    return Object.fromEntries(Object.entries(errors).filter(([key]) => !keys.includes(key))) as Record<string, string>;
}

export function useProjectCreateState(props: ProjectCreateStateProps, createForm: ProjectCreateForm) {
    const appliedOrganisationId = computed(() => props.filters.organisation_id ?? null);
    const scopedOrganisation = computed<NullableOption>(() => {
        if (appliedOrganisationId.value === null || appliedOrganisationId.value === undefined || appliedOrganisationId.value === '') {
            return null;
        }

        if (props.currentOrganisation) {
            return props.currentOrganisation;
        }

        const scopedId = Number(appliedOrganisationId.value);

        return props.organisations.find((organisation) => organisation.id === scopedId) ?? null;
    });

    watch(
        () => createForm.client_id,
        (value) => {
            const normalized = normalizeNullableId(value);
            if (normalized !== value) createForm.client_id = normalized;
        },
    );
    watch(
        () => createForm.organisation_id,
        (value) => {
            const normalized = normalizeNullableId(value);
            if (normalized !== value) createForm.organisation_id = normalized;
        },
    );

    const createDisabled = computed(() => createForm.processing || !createForm.name.trim());
    const otherCreateErrors = computed(() => omitErrors(createForm.errors, ['name', 'client_id', 'organisation_id']));

    function openCreateModal() {
        createForm.reset();
        createForm.client_id = null;
        createForm.organisation_id = scopedOrganisation.value?.id ?? null;
        createForm.isActive = true;
    }

    function closeCreateModal() {
        createForm.isActive = false;
        createForm.reset();
    }

    function submitCreateForm() {
        if (scopedOrganisation.value) {
            createForm.organisation_id = createForm.client_id === null ? scopedOrganisation.value.id : null;
        }

        createForm.post('/projects', {
            preserveScroll: true,
            onSuccess: () => closeCreateModal(),
            onError: () => {
                createForm.isActive = true;
            },
        });
    }

    return {
        closeCreateModal,
        createDisabled,
        openCreateModal,
        otherCreateErrors,
        submitCreateForm,
    };
}
