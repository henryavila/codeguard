export const configSchema = {
  type: 'object',
  required: ['version', 'project', 'capabilities', 'patterns', 'hooks', 'baseline'],
  additionalProperties: false,
  properties: {
    version: { type: 'string' },
    project: {
      type: 'object',
      required: ['language', 'framework', 'module'],
      additionalProperties: false,
      properties: {
        language: { type: 'string' },
        framework: { type: 'string' },
        module: { type: 'string' },
      },
    },
    capabilities: {
      type: 'object',
      additionalProperties: {
        type: 'object',
        required: ['enabled', 'enforcement'],
        additionalProperties: false,
        properties: {
          enabled: { type: 'boolean' },
          enforcement: { type: 'string', enum: ['block', 'warn', 'autofix'] },
          level: { type: 'integer' },
          presets: { type: 'array', items: { type: 'string' } },
        },
      },
    },
    patterns: {
      type: 'object',
      required: ['catalog', 'discovered', 'custom'],
      additionalProperties: false,
      properties: {
        catalog: { type: 'array', items: { type: 'string' } },
        discovered: { type: 'array', items: { type: 'string' } },
        custom: { type: 'array', items: { type: 'string' } },
      },
    },
    thresholds: {
      type: 'object',
      additionalProperties: false,
      properties: {
        max_method_lines: { type: 'integer' },
        max_indentation_levels: { type: 'integer' },
      },
    },
    hooks: {
      type: 'object',
      additionalProperties: {
        type: 'object',
        required: ['enabled', 'scope'],
        additionalProperties: false,
        properties: {
          enabled: { type: 'boolean' },
          scope: { type: 'string', enum: ['staged-files'] },
        },
      },
    },
    baseline: {
      type: 'object',
      required: ['path'],
      additionalProperties: false,
      properties: {
        path: { type: 'string' },
        generated: { type: 'string' },
      },
    },
  },
} as const;
