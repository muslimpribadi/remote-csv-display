# Remote CSV Display for SIMPANG.ID
Wordpress plugin for displaying CSV from remote URL, fetches, caches, and displays data from a remote CSV file using a shortcode, update daily at GMT+7. Build for simpang.id

## How to use
Shortcode `[remote_csv_display url="http://example.com/data.csv" hide="id,name,address"]`

Parameter:
- `hide` to hide selected column in default table mode, seperated by comma.
- `grouped-timeline` activate groupe timeline output with interaactive line chart.

Grouped Timeline mode `[remote_csv_display url="http://example.com/data.csv" grouped-timeline="ID,Komoditas,Harga,Satuan"]`
- `ID` the unique value for grouping
- `Komoditas` the label
- `Harga` the serial values for y-axis
- `Satuan` the label for y-axis
- Requirements: the 1st column of the CSV are in timestamp format `YYYY-MM-DD`

## Limitation
Limit 1500 last records of the CSV for performance and realistic use case.

## Disclaimer
This software is provided "as is", without warranty of any kind, express or implied, including but not limited to the warranties of merchantability, fitness for a particular purpose, and noninfringement. The authors and contributors shall not be held liable for any claim, damages, or other liability, whether in an action of contract, tort, or otherwise, arising from, out of, or in connection with the software or the use or other dealings in the software.
Use it at your own risk. Errors, bugs, or data loss may occur, and you are solely responsible for any consequences resulting from the use of this plugin.
