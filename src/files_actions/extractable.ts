// The MIME types ApiController reads text from (TEXT_MIME_TYPES and
// BINARY_MIME_TYPES), matched by prefix as it does. Keep the two in step:
// tests/unit/files_actions/extractable.spec.ts compares them.
export const EXTRACTABLE_MIME_TYPES = [
	'text/plain',
	'text/markdown',
	'text/csv',
	'text/html',
	'text/xml',
	'text/rtf',
	'application/json',
	'application/xml',
	'application/rtf',
	'application/pdf',
	'application/msword',
	'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
	'application/vnd.oasis.opendocument.text',
	'application/vnd.ms-excel',
	'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
	'application/vnd.oasis.opendocument.spreadsheet',
	'application/vnd.ms-powerpoint',
	'application/vnd.openxmlformats-officedocument.presentationml.presentation',
	'application/vnd.oasis.opendocument.presentation',
]

/**
 * Whether the app can read text from a file of this type, for Summarize and
 * Translate.
 *
 * @param mime The file's MIME type
 */
export function isExtractable(mime: string): boolean {
	return EXTRACTABLE_MIME_TYPES.some((prefix) => mime.startsWith(prefix))
}
