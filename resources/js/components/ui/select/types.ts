export type SelectOptionValue = string | number | null;

export type SelectOption = {
    value: SelectOptionValue;
    label: string;
    description?: string;
    disabled?: boolean;
    keywords?: string[];
};
