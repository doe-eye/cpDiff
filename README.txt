DESCRIPTION:
displays cp-time differences to a tracked record - either to pb or to a specific dedimania/local record

 in the middle (instead of modescript_settings.xml <checkpoint_time>)
 -> shows current cp-time difference when crossing a cp / finish
 -> hidden 2 seconds after crossing cp / finish

 at bottom (instead of checkpoints.xml <time_diff_widget> and its colorbar)
 -> shows current cp-time difference when crossing and till next crossing a cp
 -> shows also the kind and number of the tracked record
 -> shows a colorbar

 at top
 -> shows the cp-time differences of every crossed cp (only if in cpDiff.xml indicated number of rows to show is not exceeded)

 

INSTALLATION:
1) put file plugin.checkpoint_time_differences.php into folder uasaco/plugins

2) put file checkpoint_time_differences.xml into folder uaseco/config (edit this file to match your needs)

3) edit file uaseco/config/plugins.xml:
	add the line:
   <plugin>plugin.checkpoint_time_differences.php</plugin>
   
4) edit file uaseco/config/modescript_settings.xml:
   set
   	<checkpoint_time>
		<visible>true</visible>
   to
		<visible>false</visible>
   
5) edit file uaseco/config/checkpoints.xml:
	set
	<time_diff_widget>
		<enabled>true</enabled>
		<enable_colorbar>true</enable_colorbar>
	to
		<enabled>false</enabled>
		<enable_colorbar>false</enable_colorbar>
   
6) restart uaseco



