#!/usr/bin/env python3
"""Exact find/replace that keeps each file's own line endings (CRLF stays CRLF).

    from patch_keep_eol import patch
    patch('app/x.php', [(old, new), ...])   # old/new written with \n

Each `old` must match exactly once (whitespace included) or the script stops,
so a fix is never applied to the wrong place.
"""
import re, sys

def patch(path, pairs):
    s = open(path, encoding='utf-8', newline='').read()
    for old, new in pairs:
        pat = re.escape(old).replace('\\\n', '\n').replace('\n', r'\r?\n')
        ms = list(re.finditer(pat, s))
        if len(ms) != 1:
            sys.exit(f'{path}: {len(ms)} matches for {old[:80]!r}')
        m = ms[0]; seg = m.group(0)
        if '\r\n' in seg: eol = '\r\n'
        elif '\n' in seg: eol = '\n'
        else: eol = '\r\n' if s.count('\r\n') * 2 > s.count('\n') else '\n'
        s = s[:m.start()] + new.replace('\n', eol) + s[m.end():]
    open(path, 'w', encoding='utf-8', newline='').write(s)

if __name__ == '__main__':
    print(__doc__)
