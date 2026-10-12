import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

describe('reduced motion styles', () => {
    it('keeps reduced-motion feedback without a global animation kill switch', () => {
        const stylesheet = readFileSync(join(process.cwd(), 'resources/css/app.css'), 'utf8');
        const motion = readFileSync(join(process.cwd(), 'resources/js/shared/styles/motion.css'), 'utf8');
        expect(stylesheet).toContain("@import '../js/shared/styles/motion.css'");
        expect(stylesheet + motion).not.toMatch(/(?:animation|transition)-duration:\s*1ms\s*!important/);
        expect(motion).toContain('@media (prefers-reduced-motion: reduce)');
        expect(motion).toContain('shift-reduced-in');
        expect(motion).toContain('shift-reduced-out');
        expect(motion).not.toMatch(/transform:|translate:|scale:/);
    });
});
