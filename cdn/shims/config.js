/**
 * Shim for Moodle's core/config module.
 *
 * Moodle's core/config exposes site-wide config values (sesskey, wwwroot,
 * etc.) populated into the global `M.cfg` object on every Moodle page.
 * The shim re-exports those values so SOLA AMD modules that
 * `define(['core/config'], ...)` resolve correctly under the CDN mini AMD
 * loader. Used by amd/src/repository.js for Config.sesskey + Config.wwwroot
 * when building file-upload URLs.
 */

const cfg = (window.M && window.M.cfg) ? window.M.cfg : {};

export default cfg;
export {cfg};
