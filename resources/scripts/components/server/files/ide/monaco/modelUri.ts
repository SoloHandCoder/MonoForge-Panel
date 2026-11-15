/**
 * The Monaco model URI of a server file. Each path segment is encoded on its own so the
 * slashes stay slashes: encoding the whole path ("file:///%2F.env") decodes to the invalid
 * "//.env", and Monaco threw for every file whose path starts with "/" (all of them).
 */
export function modelPath(path: string): string {
    const segments = path
        .split('/')
        .filter((segment) => segment !== '')
        .map((segment) => encodeURIComponent(segment));

    return `file:///${segments.join('/')}`;
}
