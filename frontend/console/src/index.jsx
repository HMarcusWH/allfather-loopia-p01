import "./styles/index.css";
import { createConsoleApp } from "./bootstrap/create-console-app.jsx";
import { HttpConsoleDataSource } from "./data/http-console-data-source.js";

const mount = document.querySelector("[data-allfather-console]");
if (mount) {
  createConsoleApp({ mount, datasource: new HttpConsoleDataSource() });
}
