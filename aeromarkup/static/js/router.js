/* AeroMarkup — minimal hash router. Routes: #/<name>/<param?> */
const routes = new Map();
let notFound = null;

export function route(name, handler) { routes.set(name, handler); }
export function setNotFound(fn) { notFound = fn; }

export function parseHash() {
  const h = (location.hash || "#/dashboard").replace(/^#\/?/, "");
  const [name, ...rest] = h.split("/");
  return { name: name || "dashboard", param: rest.join("/") || null };
}

export function navigate(path) { location.hash = "#/" + path; }

export function startRouter() {
  const run = () => {
    const { name, param } = parseHash();
    const h = routes.get(name);
    if (h) h(param); else notFound && notFound(name);
  };
  window.addEventListener("hashchange", run);
  run();
}
