/**
 * Media path helpers for chat markdown rendering.
 *
 * Resolves SelfHelp asset paths with BASE_PATH (e.g. `/selfhelp` or `/`)
 * and promotes bare media lines into markdown image syntax so relative
 * `/assets/...` paths render as players (GFM only autolinks absolute URLs).
 */

export const VIDEO_EXTENSIONS = ['.mp4', '.webm', '.ogv', '.mov', '.m4v'];
export const AUDIO_EXTENSIONS = ['.mp3', '.wav', '.ogg', '.oga', '.m4a', '.aac', '.flac'];

declare const BASE_PATH: string | undefined;

/** SelfHelp install prefix (`/selfhelp`, `/`, or empty). */
export function getBasePath(): string {
  try {
    if (typeof BASE_PATH !== 'undefined' && BASE_PATH) {
      return String(BASE_PATH).replace(/\/$/, '');
    }
  } catch (_e) {
    // BASE_PATH may be undeclared in some bundles
  }
  const fromWindow = (window as unknown as { BASE_PATH?: string }).BASE_PATH;
  if (typeof fromWindow === 'string' && fromWindow) {
    return fromWindow.replace(/\/$/, '');
  }
  return '';
}

export function urlHasExtension(src: string, extensions: string[]): boolean {
  const lowerSrc = src.toLowerCase().split('#')[0];
  return extensions.some(ext => lowerSrc.endsWith(ext) || lowerSrc.includes(ext + '?'));
}

export function isAudioUrl(src: string, alt?: string): boolean {
  if (alt?.toLowerCase().startsWith('audio')) {
    return true;
  }
  return urlHasExtension(src, AUDIO_EXTENSIONS);
}

export function isVideoUrl(src: string, alt?: string): boolean {
  if (alt?.toLowerCase().startsWith('audio')) {
    return false;
  }
  if (alt?.toLowerCase().startsWith('video')) {
    return true;
  }
  return urlHasExtension(src, VIDEO_EXTENSIONS);
}

/**
 * Resolve a media src for use in <img>/<video>/<audio>.
 * Prepends BASE_PATH for site-relative paths without double-prefixing.
 */
export function resolveMediaPath(src: string): string {
  if (!src) {
    return src;
  }

  const trimmed = src.trim();
  if (/^(https?:|data:|blob:)/i.test(trimmed) || trimmed.startsWith('//')) {
    return trimmed;
  }

  let path = trimmed;
  if (!path.startsWith('/')) {
    path = path.startsWith('assets/') ? `/${path}` : `/assets/${path}`;
  }

  const base = getBasePath();
  if (!base) {
    return path;
  }
  if (path === base || path.startsWith(`${base}/`)) {
    return path;
  }
  return `${base}${path}`;
}

/**
 * Convert bare media paths/URLs on their own line into markdown image syntax
 * so relative `/assets/...` video and audio files become players.
 *
 * Already-markdown lines (`![...](...)`, `[text](...)`) are left untouched.
 */
export function promoteBareMediaLines(content: string): string {
  const extPattern = [...VIDEO_EXTENSIONS, ...AUDIO_EXTENSIONS]
    .map(ext => ext.replace('.', '\\.'))
    .join('|');

  const bareLine = new RegExp(
    `^([ \\t]*)(?!\\!\\[)(?!\\[)((?:https?:\\/\\/[^\\s]+|\\/?[^\\s]+?)(?:${extPattern})(?:\\?[^\\s]*)?)([ \\t]*)$`,
    'gmi'
  );

  return content.replace(bareLine, (_full, indent: string, url: string, trailing: string) => {
    if (isAudioUrl(url)) {
      return `${indent}![audio](${url})${trailing}`;
    }
    if (isVideoUrl(url)) {
      return `${indent}![video](${url})${trailing}`;
    }
    return `${indent}${url}${trailing}`;
  });
}
