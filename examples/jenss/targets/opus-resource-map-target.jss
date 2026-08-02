#!jenss

use @system, @io from system;

load resources from opus_runtime_resources;
map @system: $history to __HISTORY__;
map @system: $memory to __ABS_MEMORY__;
map @io: $output to __IO_OUTPUT__;

say "Resource map loaded.";
