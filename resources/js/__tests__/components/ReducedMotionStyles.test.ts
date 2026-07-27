import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

describe('reduced motion styles', () => {
    it('removes spatial animation and smooth scrolling at the shared stylesheet boundary', () => {
        const stylesheet = readFileSync(join(process.cwd(), 'resources/css/app.css'), 'utf8');

        expect(stylesheet).toContain('@media (prefers-reduced-motion: reduce)');
        expect(stylesheet).toContain('animation-duration: 1ms !important;');
        expect(stylesheet).toContain('transition-duration: 1ms !important;');
        expect(stylesheet).toContain('scroll-behavior: auto !important;');
    });
});
