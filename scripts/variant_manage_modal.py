import re
from pathlib import Path

blade = Path(__file__).resolve().parents[1] / "resources/views/retail-products/family.blade.php"
text = blade.read_text(encoding="utf-8")

text = text.replace(
    "data-rfm-manage-toggle\n                                                aria-expanded=\"false\"",
    "data-rfm-manage-open\n                                                aria-haspopup=\"dialog\"",
)

text = text.replace(
    '<motion class="rfm-variant-manage" data-rfm-manage-panel hidden>',
    '<div class="rfm-variant-manage-source" data-rfm-manage-source hidden>',
)
text = text.replace(
    '<div class="rfm-variant-manage" data-rfm-manage-panel hidden>',
    '<motion class="rfm-variant-manage-source" data-rfm-manage-source hidden>',
)
text = text.replace(
    '<motion class="rfm-variant-manage-source" data-rfm-manage-source hidden>',
    '<div class="rfm-variant-manage-source" data-rfm-manage-source hidden>',
)

add_block = re.compile(
    r'                                        <div class="rfm-variant-manage-add">\s*'
    r'<input type="text"\s*'
    r'class="rfm-variant-manage-add-input"\s*'
    r'data-rfm-manage-add-input\s*'
    r'placeholder="([^"]+)"\s*'
    r'maxlength="255"\s*'
    r'autocomplete="off">\s*'
    r'<button type="button" class="rfm-variant-manage-btn rfm-variant-manage-btn-primary" data-rfm-manage-add>Add</button>\s*'
    r'</div>',
    re.MULTILINE,
)

def strip_add(m):
    ph = m.group(1)
    return (
        '                                        <input type="text"\n'
        '                                               class="rfm-variant-manage-add-input"\n'
        '                                               data-rfm-manage-add-input\n'
        '                                               value=""\n'
        f'                                               placeholder="{ph}"\n'
        '                                               maxlength="255"\n'
        '                                               autocomplete="off"\n'
        '                                               tabindex="-1"\n'
        '                                               aria-hidden="true">'
    )

text = add_block.sub(strip_add, text)

modal = '''
        <div class="rfm-quick-overlay rfm-variant-manage-overlay" data-rfm-variant-manage-modal hidden aria-hidden="true">
            <button type="button" class="rfm-quick-backdrop" data-rfm-variant-manage-close aria-label="Close"></button>
            <section class="rfm-quick-panel rfm-variant-manage-panel" role="dialog" aria-modal="true" aria-labelledby="rfm-variant-manage-title">
                <header class="rfm-variant-manage-head">
                    <div>
                        <span class="rfm-variant-manage-eyebrow">Variant values</span>
                        <strong id="rfm-variant-manage-title" data-rfm-variant-manage-title>Values</strong>
                    </div>
                    <button type="button" class="rfm-variant-manage-close" data-rfm-variant-manage-close aria-label="Close">×</button>
                </header>
                <motion class="rfm-variant-manage-body">
                    <ul class="rfm-variant-manage-list" data-rfm-manage-list></ul>
                    <p class="rfm-variant-manage-empty" data-rfm-manage-empty hidden>No values yet — add one below.</p>
                    <div class="rfm-variant-manage-add">
                        <input type="text"
                               class="rfm-variant-manage-add-input"
                               data-rfm-manage-add-input
                               placeholder="Add a value"
                               maxlength="255"
                               autocomplete="off">
                        <button type="button" class="rfm-variant-manage-btn rfm-variant-manage-btn-primary" data-rfm-manage-add>Add</button>
                    </div>
                </div>
                <footer class="rfm-variant-manage-foot">
                    <button type="button" class="rfm-variant-manage-done" data-rfm-variant-manage-close>Done</button>
                </footer>
            </section>
        </div>

'''
modal = modal.replace('<motion class="rfm-variant-manage-body">', '<div class="rfm-variant-manage-body">')

if 'data-rfm-variant-manage-modal' not in text:
    text = text.replace(
        '        <div class="rfm-toast" data-rfm-toast hidden></div>',
        modal + '        <div class="rfm-toast" data-rfm-toast hidden></div>',
        1,
    )

blade.write_text(text, encoding="utf-8")
print('ok')
