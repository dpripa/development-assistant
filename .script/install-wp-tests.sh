ENV_FILE="$(dirname "$0")/../.env"

if [ -f "$ENV_FILE" ]; then
  echo "Loading environment from $ENV_FILE"
  export $(grep -v '^#' "$ENV_FILE" | xargs)
else
  echo ".env file not found"
  exit 1
fi

TMPDIR="$(pwd)/tmp"
TMPDIR=$(echo $TMPDIR | sed -e "s/\/$//")
WP_TESTS_DIR=${WP_TESTS_DIR-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress}

download() {
    if [ `which curl` ]; then
        curl -s "$1" > "$2";
    elif [ `which wget` ]; then
        wget -nv -O "$2" "$1"
    fi
}

if [[ $TEST_WP_VERSION =~ ^[0-9]+\.[0-9]+\-(beta|RC)[0-9]+$ ]]; then
	WP_BRANCH=${TEST_WP_VERSION%\-*}
	WP_TESTS_TAG="branches/$WP_BRANCH"

elif [[ $TEST_WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
	WP_TESTS_TAG="branches/$TEST_WP_VERSION"
elif [[ $TEST_WP_VERSION =~ [0-9]+\.[0-9]+\.[0-9]+ ]]; then
	if [[ $TEST_WP_VERSION =~ [0-9]+\.[0-9]+\.[0] ]]; then
		WP_TESTS_TAG="tags/${TEST_WP_VERSION%??}"
	else
		WP_TESTS_TAG="tags/$TEST_WP_VERSION"
	fi
elif [[ $TEST_WP_VERSION == 'nightly' || $TEST_WP_VERSION == 'trunk' ]]; then
	WP_TESTS_TAG="trunk"
else
	download http://api.wordpress.org/core/version-check/1.7/ /tmp/wp-latest.json
	grep '[0-9]+\.[0-9]+(\.[0-9]+)?' /tmp/wp-latest.json
	LATEST_VERSION=$(grep -o '"version":"[^"]*' /tmp/wp-latest.json | sed 's/"version":"//')
	if [[ -z "$LATEST_VERSION" ]]; then
		echo "Latest WordPress version could not be found"
		exit 1
	fi
	WP_TESTS_TAG="tags/$LATEST_VERSION"
fi
set -ex

install_wp() {
	if [ -d $WP_CORE_DIR ]; then
		return;
	fi

	mkdir -p $WP_CORE_DIR

	if [[ $TEST_WP_VERSION == 'nightly' || $TEST_WP_VERSION == 'trunk' ]]; then
		mkdir -p $TMPDIR/wordpress-trunk
		rm -rf $TMPDIR/wordpress-trunk/*
		svn export --quiet https://core.svn.wordpress.org/trunk $TMPDIR/wordpress-trunk/wordpress
		mv $TMPDIR/wordpress-trunk/wordpress/* $WP_CORE_DIR
	else
		if [ $TEST_WP_VERSION == 'latest' ]; then
			local ARCHIVE_NAME='latest'
		elif [[ $TEST_WP_VERSION =~ [0-9]+\.[0-9]+ ]]; then
			download https://api.wordpress.org/core/version-check/1.7/ $TMPDIR/wp-latest.json
			if [[ $TEST_WP_VERSION =~ [0-9]+\.[0-9]+\.[0] ]]; then
				LATEST_VERSION=${TEST_WP_VERSION%??}
			else
				local VERSION_ESCAPED=`echo $TEST_WP_VERSION | sed 's/\./\\\\./g'`
				LATEST_VERSION=$(grep -o '"version":"'$VERSION_ESCAPED'[^"]*' $TMPDIR/wp-latest.json | sed 's/"version":"//' | head -1)
			fi
			if [[ -z "$LATEST_VERSION" ]]; then
				local ARCHIVE_NAME="wordpress-$TEST_WP_VERSION"
			else
				local ARCHIVE_NAME="wordpress-$LATEST_VERSION"
			fi
		else
			local ARCHIVE_NAME="wordpress-$TEST_WP_VERSION"
		fi
		download https://wordpress.org/${ARCHIVE_NAME}.tar.gz  $TMPDIR/wordpress.tar.gz
		tar --strip-components=1 -zxmf $TMPDIR/wordpress.tar.gz -C $WP_CORE_DIR
	fi

	download https://raw.githubusercontent.com/markoheijnen/wp-mysqli/master/db.php $WP_CORE_DIR/wp-content/db.php
}

install_test_suite() {
	if [[ $(uname -s) == 'Darwin' ]]; then
		local ioption='-i.bak'
	else
		local ioption='-i'
	fi

	if [ ! -d $WP_TESTS_DIR ]; then
		mkdir -p $WP_TESTS_DIR
		rm -rf $WP_TESTS_DIR/{includes,data}
		svn export --quiet --ignore-externals https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/ $WP_TESTS_DIR/includes
		svn export --quiet --ignore-externals https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/ $WP_TESTS_DIR/data
	fi

	if [ ! -f wp-tests-config.php ]; then
		download https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php "$WP_TESTS_DIR"/wp-tests-config.php
		WP_CORE_DIR=$(echo $WP_CORE_DIR | sed "s:/\+$::")
		sed $ioption "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s:__DIR__ . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s/youremptytestdbnamehere/$TEST_DB_NAME/" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s/yourusernamehere/$TEST_DB_USER/" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s/yourpasswordhere/$TEST_DB_PWD/" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s|localhost|${TEST_DB_HOST}|" "$WP_TESTS_DIR"/wp-tests-config.php
	fi

}

recreate_db() {
	shopt -s nocasematch
	if [[ $1 =~ ^(y|yes)$ ]]
	then
		mysqladmin drop $TEST_DB_NAME -f --user="$TEST_DB_USER" --password="$TEST_DB_PWD"$EXTRA
		create_db
		echo "Recreated the database ($TEST_DB_NAME)."
	else
		echo "Leaving the existing database ($TEST_DB_NAME) in place."
	fi
	shopt -u nocasematch
}

create_db() {
	mysqladmin create $TEST_DB_NAME --user="$TEST_DB_USER" --password="$TEST_DB_PWD"$EXTRA
}

install_db() {
	if [ ${TEST_SKIP_DB_CREATE} = "true" ]; then
		return 0
	fi

	local PARTS=(${TEST_DB_HOST//\:/ })
	local TEST_DB_HOSTNAME=${PARTS[0]};
	local DB_SOCK_OR_PORT=${PARTS[1]};
	local EXTRA=""

	if ! [ -z $TEST_DB_HOSTNAME ] ; then
		if [ $(echo $DB_SOCK_OR_PORT | grep -e '^[0-9]\{1,\}$') ]; then
			EXTRA=" --host=$TEST_DB_HOSTNAME --port=$DB_SOCK_OR_PORT --protocol=tcp"
		elif ! [ -z $DB_SOCK_OR_PORT ] ; then
			EXTRA=" --socket=$DB_SOCK_OR_PORT"
		elif ! [ -z $TEST_DB_HOSTNAME ] ; then
			EXTRA=" --host=$TEST_DB_HOSTNAME --protocol=tcp"
		fi
	fi

	if [ $(mysql --user="$TEST_DB_USER" --password="$TEST_DB_PWD"$EXTRA --execute='show databases;' | grep ^$TEST_DB_NAME$) ]
	then
		echo "Reinstalling will delete the existing test database ($TEST_DB_NAME)"
		read -p 'Are you sure you want to proceed? [y/N]: ' DELETE_EXISTING_DB
		recreate_db $DELETE_EXISTING_DB
	else
		create_db
	fi
}

install_wp
install_test_suite
install_db

if [ ${TEST_SKIP_DB_CREATE} = "false" ]; then
	sed -i.bak 's/^TEST_SKIP_DB_CREATE=false/TEST_SKIP_DB_CREATE=true/' $ENV_FILE
fi
