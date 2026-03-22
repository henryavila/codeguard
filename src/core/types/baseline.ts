export interface BaselineEntry {
  tool: string;
  rule: string;
  file: string;
  message_normalized: string;
  hash: string;
}

export interface BaselineFile {
  version: string;
  generated: string;
  generatedBy: string;
  module: string;
  entries: BaselineEntry[];
}
