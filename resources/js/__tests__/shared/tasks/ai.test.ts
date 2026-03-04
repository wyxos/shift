import { buildThreadAiContext } from '@shared/tasks/ai';
import { describe, expect, it } from 'vitest';

describe('shared/tasks/ai', () => {
    it('returns empty context when no messages are provided', () => {
        expect(buildThreadAiContext([])).toBe('');
    });

    it('builds concise thread context from recent messages', () => {
        const context = buildThreadAiContext([
            { author: 'Alice', time: '10:00', content: '<p>Need an update on deployment status.</p>' },
            { author: 'Bob', time: '10:05', content: '<p>Deployment is done. Monitoring for regressions.</p>' },
        ]);

        expect(context).toContain('Recent thread context (oldest to newest):');
        expect(context).toContain('1. Alice (10:00): Need an update on deployment status.');
        expect(context).toContain('2. Bob (10:05): Deployment is done. Monitoring for regressions.');
    });

    it('keeps only newest lines when exceeding configured max size', () => {
        const context = buildThreadAiContext(
            [
                { author: 'Alice', content: '<p>First message with useful context and extra detail.</p>' },
                { author: 'Bob', content: '<p>Second message with useful context and extra detail.</p>' },
                { author: 'Carol', content: '<p>Third message with useful context and extra detail.</p>' },
            ],
            { maxCharacters: 95 },
        );

        expect(context).toContain('Carol');
        expect(context).not.toContain('Alice');
    });
});
