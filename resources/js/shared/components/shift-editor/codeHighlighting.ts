import cssLang from 'highlight.js/lib/languages/css';
import jsLang from 'highlight.js/lib/languages/javascript';
import jsonLang from 'highlight.js/lib/languages/json';
import phpLang from 'highlight.js/lib/languages/php';
import pythonLang from 'highlight.js/lib/languages/python';
import tsLang from 'highlight.js/lib/languages/typescript';
import htmlLang from 'highlight.js/lib/languages/xml';
import { createLowlight } from 'lowlight';

export function createEditorLowlight() {
    const lowlight = createLowlight();
    lowlight.register({ javascript: jsLang, js: jsLang });
    lowlight.register({ typescript: tsLang, ts: tsLang });
    lowlight.register({ json: jsonLang });
    lowlight.register({ css: cssLang });
    lowlight.register({ php: phpLang });
    lowlight.register({ html: htmlLang, xml: htmlLang });
    lowlight.register({ python: pythonLang, py: pythonLang });
    return lowlight;
}
