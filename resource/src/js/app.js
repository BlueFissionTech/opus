// Styles (required)
import '../scss/app.scss'

// Common (required)
import "./modules/bootstrap";
import "./modules/css-variables";
import "./modules/lucide";
import "./modules/sidebar";
import "./modules/theme-toggle";

// API and Endpoints
import BlueFissionAPI from "./modules/app/bluefission-api.js";

// Dashboard UI (requires jQuery)
import DashboardUI from "./modules/dashboard-ui/dashboard-ui.js";
import "./modules/dashboard-ui/dashboard-form.js";
import "./modules/dashboard-ui/record-set.js";
import "./modules/dashboard-ui/dashboard-response.js";
import "./modules/dashboard-ui/dashboard-storage.js";
import "./modules/dashboard-ui/dashboard-module.js";
import "./modules/dashboard-ui/portlet-ui.js";
import "./modules/dashboard-ui/convert-colors.js";

// Framework utils
import { computed, get, set, assign } from "./modules/scripts/reactive_template.js";

// Common (optional)
import "./modules/moment";
import "./modules/dragula";
import "./modules/notyf";

// Charts (optional)
import "./modules/chartjs";
import "./modules/apexcharts";

// Forms (optional)
import "./modules/daterangepicker"; // requires jQuery
import "./modules/tempus-dominus";
import "./modules/fullcalendar";
import "./modules/mask"; // requires jQuery
import "./modules/quill";
import "./modules/select2"; // requires jQuery
import "./modules/validation"; // requires jQuery
import "./modules/wizard"; // requires jQuery

// Maps (optional)
import "./modules/vector-maps";

// Tables (optional)
import "./modules/datatables"; // requires jQuery

const App = {
  api: BlueFissionAPI,
  ui: DashboardUI,
  get: get,
  set: set,
  assign: assign,
  computed: computed,
};

window.app = App;
export default App;