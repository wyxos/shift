// Format the source tokens so large numbers, escapes and duplicate keys survive unchanged.
export function prettyJson(source: string): string {
    const tokens = source.match(/"(?:\\[\s\S]|[^"\\])*"|[{}\[\],:]|[^\s"{}\[\],:]+/g) ?? [];
    let depth = 0;
    const newline = () => '\n' + '  '.repeat(Math.min(depth, 40));

    return tokens
        .map((token, index) => {
            switch (token) {
                case '{':
                case '[':
                    depth += 1;
                    return token + (tokens[index + 1] === (token === '{' ? '}' : ']') ? '' : newline());
                case '}':
                case ']':
                    depth -= 1;
                    return (tokens[index - 1] === (token === '}' ? '{' : '[') ? '' : newline()) + token;
                case ',':
                    return token + newline();
                case ':':
                    return ': ';
                default:
                    return token;
            }
        })
        .join('');
}
