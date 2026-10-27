# Simulation reports

Run a simulation without specifying a filename:

```sh
php bin/simulate-report.php 1 1000 100
```

Reports are saved under `storage/` with the world ID and UTC date/time, including microseconds, for example `simulation-report-world-1-2026-10-27_18-30-00-123456Z.csv`. The command prints the saved path when finished. The timestamp identifies when the run started, not the simulated date.

You can still supply a custom CSV path as the fourth argument. Existing files are never overwritten: the command stops before advancing any ticks if the chosen output already exists. Choose a new filename and rerun it.

Open the CSV in Excel, Google Sheets or LibreOffice Calc.
