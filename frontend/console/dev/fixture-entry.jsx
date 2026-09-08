import "../src/styles/index.css";
import { createConsoleApp } from "../src/bootstrap/create-console-app.jsx";
import { FixtureConsoleDataSource } from "../fixtures/fixture-console-data-source.js";
const mount = document.querySelector("[data-allfather-console]");
if (mount) createConsoleApp({ mount, datasource: new FixtureConsoleDataSource() });
