#!/bin/sh

WHOAMI=`python -c 'import os, sys; print os.path.realpath(sys.argv[1])' $0`

UBUNTU=`dirname $WHOAMI`
PROJECT=`dirname $UBUNTU`
PROJECT_NAME=`basename ${PROJECT}`

APACHE="${PROJECT}/apache"
CONF="${APACHE}/${PROJECT_NAME}.conf"

for mod in proxy_wstunnel.load proxy.load proxy.conf
do
    
    if [ -L /etc/apache2/mods-enabled/${mod} ]
    then
        sudo rm /etc/apache2/mods-enabled/${mod}
    fi

    if [ -f /etc/apache2/mods-enabled/${mod} ]
    then
        sudo mv /etc/apache2/mods-enabled/${mod} /etc/apache2/mods-enabled/${mod}.bak
    fi

    sudo ln -s /etc/apache2/mods-available/${mod} /etc/apache2/mods-enabled/${mod}
done

if [ ! -f ${CONF}.example ]
then
    echo "missing example ${CONF}"
    exit 1
fi 

if [ ! -f ${CONF} ]
then
    cp ${CONF}.example ${CONF}

    perl -p -i -e "s!__PROJECT_ROOT__!${PROJECT}!" ${CONF}
    perl -p -i -e "s!__PROJECT_NAME__!${PROJECT_NAME}!" ${CONF}
fi

if [ -L /etc/apache2/sites-enabled/000-default.conf ]
then
    sudo rm /etc/apache2/sites-enabled/000-default.conf
fi

if [ -L /etc/apache2/sites-enabled/${PROJECT_NAME}.conf ]
then
    sudo rm /etc/apache2/sites-enabled/${PROJECT_NAME}.conf
fi

sudo ln -s ${CONF} /etc/apache2/sites-enabled/${PROJECT_NAME}.conf 

sudo /etc/init.d/apache2 restart