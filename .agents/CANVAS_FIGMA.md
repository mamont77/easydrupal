# Canvas + Figma Workflow

When the user provides:
- Component name (kebab-case)
- Figma URL with `node-id=...`

Do:

```bash
ddev drush config:export -y
# (Fetch Figma code + screenshot via MCP)
ddev drush config:import -y
ddev drush cr
```

## Canvas YAML rules

- Keep `status: false`
- Props syntax:

```yaml
props:
  heading:
    title: "Section heading"
    type: string
    examples:
      - "Example heading"
```

## Forbidden in Canvas JS

- No dynamic `style={{ ... }}` (esp. colors)
- No `styled-jsx`
- No CSS custom props driven by props

Use Tailwind classes with hardcoded colors instead.

## Responsive

- Mobile-first + Tailwind breakpoints: `sm`, `md`, `lg`, `xl`
- Touch-friendly targets, sensible spacing, wrapping layouts.
