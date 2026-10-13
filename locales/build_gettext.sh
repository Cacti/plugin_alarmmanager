#!/bin/sh
#+-------------------------------------------------------------------------+
#| Copyright (C) 2004-2026 Sean Mancini                                    |
#|                                                                         |
#| This program is free software; you can redistribute it and/or           |
#| modify it under the terms of the GNU General Public License             |
#| as published by the Free Software Foundation; either version 2          |
#| of the License, or (at your option) any later version.                  |
#|                                                                         |
#| This program is distributed in the hope that it will be useful,         |
#| but WITHOUT ANY WARRANTY; without even the implied warranty of          |
#| MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
#| GNU General Public License for more details.                            |
#+-------------------------------------------------------------------------+
#| Alarm Manager: centralized alarm history, routing and delivery for Cacti |
#+-------------------------------------------------------------------------+
#| https://github.com/bmfmancini/plugin_alarmmanager                       |
#+-------------------------------------------------------------------------+

# get script name
SCRIPT_NAME=`basename ${0}`

# locate base directory of the plugin
REALPATH_BIN=`which realpath 2>/dev/null`
if [ $? -gt 0 ]
then
	echo "ERROR: unable to locate realpath"
	echo
	echo "Linux: Confirm coreutils installed"
	echo
	exit 1
fi
BASE_PATH=`${REALPATH_BIN} ${0} | sed s#/locales/${SCRIPT_NAME}##`

# locate xgettext for processing
XGETTEXT_BIN=`which xgettext 2>/dev/null`
if [ $? -gt 0 ]
then
	echo "ERROR: Unable to locate xgettext"
	echo
	echo "Linux: Install GNU gettext"
	echo
	exit 1
fi

# Update the plugin gettext POT file with the shipped strings
echo "Updating Alarm Manager gettext language file..."
cd ${BASE_PATH}

${XGETTEXT_BIN} --no-wrap --from-code=UTF-8 --copyright-holder="Sean Mancini" --package-name="Alarm Manager" --package-version=`grep -E '^version' INFO | cut -d= -f2 | tr -d ' '` --msgid-bugs-address="https://github.com/bmfmancini/plugin_alarmmanager/issues" -F -k__gettext -k__ -k__n:1,2 -k__x:1c,2 -k__xn:1c,2,3 -k__esc -k__esc_n:1,2 -k__esc_x:1c,2 -k__esc_xn:1c,2,3 -k__date -o locales/po/alarmmanager.pot `find . -maxdepth 2 -name \*.php ! -path './tests/*'`

sed -i 's|FULL NAME <EMAIL@ADDRESS>|Alarm Manager Developers <https://github.com/bmfmancini/plugin_alarmmanager>|g' locales/po/alarmmanager.pot
sed -i 's|LANGUAGE <LL@li.org>|Alarm Manager Developers <https://github.com/bmfmancini/plugin_alarmmanager>|g' locales/po/alarmmanager.pot
sed -i 's|CHARSET|UTF-8|g' locales/po/alarmmanager.pot

# Merge any changes to POT file into language files
echo "Merging updates to language files..."

for file in `ls -1 locales/po/*.po`;do
	echo "Updating $file from alarmmanager.pot"
	msgmerge --backup off --no-wrap --update -F $file locales/po/alarmmanager.pot
done

for file in `ls -1 locales/po/*.po`;do
  ofile=$(basename --suffix=.po ${file})
  echo "Converting $file to LC_MESSAGES/${ofile}.mo"
  msgfmt ${file} -o locales/LC_MESSAGES/${ofile}.mo
done

mkdir -p locales/LC_MESSAGES
exit 0