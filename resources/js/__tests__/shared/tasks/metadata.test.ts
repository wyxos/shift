import { formatEnvironmentLabel, getTaskCreatorEmail, getTaskCreatorName, getTaskEnvironment, pickFirstNonEmptyString } from '@shared/tasks/metadata';
import { describe, expect, it } from 'vitest';

describe('shared/tasks/metadata', () => {
    it('picks first non-empty string', () => {
        expect(pickFirstNonEmptyString([null, '  ', ' alpha '])).toBe('alpha');
        expect(pickFirstNonEmptyString([null, 123, false])).toBeNull();
    });

    it('resolves creator name/email from fallback fields', () => {
        const task = {
            submitter: { name: 'Jane', email: 'jane@example.com' },
            creator: { name: 'Fallback', email: 'fallback@example.com' },
        };

        expect(getTaskCreatorName(task)).toBe('Jane');
        expect(getTaskCreatorEmail(task)).toBe('jane@example.com');
    });

    it('formats environment labels', () => {
        expect(formatEnvironmentLabel('qa_env')).toBe('Qa Env');
        expect(formatEnvironmentLabel('production')).toBe('Production');
        expect(formatEnvironmentLabel('  ')).toBe('');
    });

    it('resolves and formats environment from fallback fields', () => {
        const task = {
            metadata: {
                environment: 'staging_zone',
            },
        };

        expect(getTaskEnvironment(task)).toBe('Staging Zone');
        expect(getTaskEnvironment({})).toBeNull();
    });
});
