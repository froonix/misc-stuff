#!/bin/bash
################################################
#                                              #
#  MariaDB Backup Script for Froonix           #
#  (forked from automysqlbackup v2.5)          #
#                                              #
#  v1.0.0 (2017-08-31) by Christian Schrötter  #
#  ------------------------------------------  #
#  Git: https://github.com/froonix/misc-stuff  #
#                                              #
#  THIS IS A MULTI-INSTANCE-ONLY VERSION!      #
#  SEE SYSTEMD SERVICE: MARIADB@.SERVICE       #
#                                              #
################################################

INSTANCE="$1"
DEFAULT="/etc/mysql/bkp.d/default.conf"
DEFAULTS="/etc/mysql/debian-${INSTANCE}.cnf"
CONFIG="/etc/mysql/bkp.d/my${INSTANCE}.conf"
CNF="/etc/mysql/conf.d/my${INSTANCE}.cnf"

if [[ "$1" == "" || ! -f "$CNF" || ! -e "$DEFAULTS" ]]
then echo "Invalid instance name: $1" 1>&2; exit 1
fi

if [[ -e "$DEFAULT" ]]
then . "$DEFAULT" || exit 1
fi

if [[ -e "$CONFIG" ]]
then . "$CONFIG" || exit 1
fi

if [ ! -e "$BACKUPDIR" ]
then echo "Invalid BACKUPDIR: $BACKUPDIR" 1>&2; exit 1
fi

if [ "${ROUTINES}" = "yes" ]
then OPT="${OPT} --routines"
fi

if [ "$CREATE_DATABASE" = "no" ]
then OPT="$OPT --no-create-db"
else OPT="$OPT --databases"
fi

LANG=C
BACKUPFILES=
DATE=`date +%Y-%m-%d_%Hh%Mm`
DOW=`date +%A`
DNOW=`date +%u`
DOM=`date +%d`
M=`date +%B`
W=`date +%V`

PATH=/usr/local/bin:/usr/bin:/bin:/usr/local/mysql/bin
LOGFILE="$BACKUPDIR/`date +%s`.log"
LOGERR="$BACKUPDIR/`date +%s`.err"
OPT="--quote-names --events"

chmod o-x "${BACKUPDIR}"; chmod o-r "${BACKUPDIR}"
if [ ! -e "$BACKUPDIR/daily" ]; then mkdir -p "$BACKUPDIR/daily"; fi
if [ ! -e "$BACKUPDIR/weekly" ]; then mkdir -p "$BACKUPDIR/weekly"; fi
if [ ! -e "$BACKUPDIR/monthly" ]; then mkdir -p "$BACKUPDIR/monthly"; fi

touch "$LOGFILE"
touch "$LOGERR"

exec 6>&1
exec > "$LOGFILE"

exec 7>&2
exec 2> "$LOGERR"

function dbdump ()
{
	touch "$2"
	chmod 0600 "$2"

	if [[ "$1" == "information_schema" ]]
	then NEWOPT="--skip-opt ${OPT}"
	else NEWOPT="--opt $OPT"
	fi

	mysqldump --defaults-file="$DEFAULTS" $NEWOPT $1 > $2

	return 0
}

SUFFIX=""
function compression ()
{
	if [[ "$COMP" == "gzip" ]]
	then
		gzip -f "$1"
		echo
		echo Backup Information for "$1"
		gzip -l "$1.gz"
		SUFFIX=".gz"
	elif [[ "$COMP" == "bzip2" ]]
	then
		echo Compression information for "$1.bz2"
		bzip2 -f -v "$1" 2>&1
		SUFFIX=".bz2"
	elif [[ "$COMP" == "xz" ]]
	then
		echo Compression information for "$1.xz"
		xz -f -v "$1" 2>&1
		SUFFIX=".xz"
	fi

	return 0
}

echo "Backup Start Time $(date)"
echo "======================================================================"

if [[ "$DOM" == "01" ]]
then
	for MDB in $MDBNAMES
	do
		MDB="`echo $MDB | sed 's/%/ /g'`"

		if [[ ! -e "$BACKUPDIR/monthly/$MDB" ]]
		then mkdir -p "$BACKUPDIR/monthly/$MDB"
		fi

		echo "Monthly Backup of $MDB..."
		dbdump "$MDB" "$BACKUPDIR/monthly/$MDB/${MDB}_$DATE.$M.$MDB.sql"
		compression "$BACKUPDIR/monthly/$MDB/${MDB}_$DATE.$M.$MDB.sql"
		BACKUPFILES="$BACKUPFILES $BACKUPDIR/monthly/$MDB/${MDB}_$DATE.$M.$MDB.sql$SUFFIX"
		echo "----------------------------------------------------------------------"
	done
fi

for DB in $DBNAMES
do
	DB="`echo $DB | sed 's/%/ /g'`"

	if [[ ! -e "$BACKUPDIR/daily/$DB" ]]
	then mkdir -p "$BACKUPDIR/daily/$DB"
	fi

	if [[ ! -e "$BACKUPDIR/weekly/$DB" ]]
	then mkdir -p "$BACKUPDIR/weekly/$DB"
	fi

	if [[ "$DNOW" == "$DOWEEKLY" ]]
	then

		echo "Weekly Backup of Database ($DB)"
		echo "Rotating 5 weeks Backups..."

		if [ "$W" -le 05 ]; then REMW=`expr 48 + $W`
		elif [ "$W" -lt 15 ]; then REMW=0`expr $W - 5`
		else REMW=`expr $W - 5`
		fi

		rm -fv "$BACKUPDIR/weekly/$DB/${DB}_week.$REMW".*
		echo

		dbdump "$DB" "$BACKUPDIR/weekly/$DB/${DB}_week.$W.$DATE.sql"
		compression "$BACKUPDIR/weekly/$DB/${DB}_week.$W.$DATE.sql"
		BACKUPFILES="$BACKUPFILES $BACKUPDIR/weekly/$DB/${DB}_week.$W.$DATE.sql$SUFFIX"
		echo "----------------------------------------------------------------------"

	else

		echo "Daily Backup of Database ($DB)"
		echo "Rotating last weeks Backup..."
		rm -fv "$BACKUPDIR/daily/$DB"/*."$DOW".sql.*

		echo
		dbdump "$DB" "$BACKUPDIR/daily/$DB/${DB}_$DATE.$DOW.sql"
		compression "$BACKUPDIR/daily/$DB/${DB}_$DATE.$DOW.sql"
		BACKUPFILES="$BACKUPFILES $BACKUPDIR/daily/$DB/${DB}_$DATE.$DOW.sql$SUFFIX"
		echo "----------------------------------------------------------------------"

	fi
done

echo "Backup End $(date)"
echo "======================================================================"
echo "Total disk space used for backup storage..."
echo "Size - Location"
echo `du -hs "$BACKUPDIR"`

exec 1>&6 6>&-
exec 1>&7 7>&-

if [[ -s "$LOGERR" ]]
then
	cat "$LOGFILE"

	echo
	echo "###### WARNING ######"
	echo "Errors reported during execution: Backup failed!"
	echo

	cat "$LOGERR"
fi

rm -f "$LOGFILE"
rm -f "$LOGERR"

if [ -s "$LOGERR" ]
then exit 1
else exit 0
fi
