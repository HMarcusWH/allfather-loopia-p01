import "./styles/index.css";
import { createConsoleApp } from "./bootstrap/create-console-app.jsx";
import { HttpConsoleDataSource } from "./data/http-console-data-source.js";

const mount = document.querySelector("[data-allfather-console]");
if (mount) {
  // ONE reviewed attribute crosses from the server document into the shell, and it carries no
  // authority: the CSRF token for the sign-out form. Everything else the console knows it learns
  // from a governed read over the session cookie.
  createConsoleApp({
    mount,
    datasource: new HttpConsoleDataSource(),
    csrfToken: mount.dataset.csrfToken ?? null
  });
}
