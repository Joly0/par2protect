# PAR2Protect Help Text Implementation

This document provides a quick reference for adding help text to any element in the PAR2Protect plugin.

## Quick Start


```html
<dl>
  <dt style="cursor: help" class="help-trigger">Setting Name:</dt>
  <dd>
    <select name="setting_name">
      <option value="option1">Option 1</option>
      <option value="option2">Option 2</option>
    </select>
  </dd>
</dl>

<blockquote class="inline_help" style="display: none;">
  This is the help text for the setting.<br>
  <strong>Recommended:</strong> Some value.
</blockquote>
```

## Minimal required html

```html
<dl>
  <dt style="cursor: help">Setting Name:</dt>
</dl>

<blockquote class="inline_help" style="display: none;">
  This is the help text for the setting.<br>
  <strong>Recommended:</strong> Some value.
</blockquote>
```

## Styling Tips

- Use `<br>` for line breaks in help text
- Use `<strong>` tags to highlight important information
- Keep help text concise and focused on explaining the setting