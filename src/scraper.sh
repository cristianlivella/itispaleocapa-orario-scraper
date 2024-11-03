echo 'Starting scraping'

html=$(wget --header "Cookie: pasw_law_cookie=yes" -qO - https://www.itispaleocapa.edu.it/orario-classi/)

name_prefix="1 – "
name=$(echo "$html" | grep -o -P '(?<=\<h2 class\="posttitle"\>).*(?=\<\/h2\>)' | php -r 'while(($line=fgets(STDIN)) !== FALSE) echo html_entity_decode($line, ENT_QUOTES|ENT_HTML401);')
name=${name#"$name_prefix"}
pdf_url=$(echo $(echo "$html" | grep -o -P 'src="https:\/\/www\.itispaleocapa\.edu\.it[\/]{0,1}\?url=(\K.*\.pdf)') | sed 's@+@ @g;s@%@\\x@g' | xargs -0 printf "%b")

wget -qO 'orario.pdf' $pdf_url

if [ $? -ne 0 ]; then
    echo 'Unable to get the PDF file'
    exit 1
fi

echo 'Copy omonimi.json from config dir'.
cp ../config/omonimi.json . 2>/dev/null

new_hash=$(sha512sum orario.pdf)

cat ../hashes/orario.pdf.sha512 2>/dev/null | sha512sum --check 2>/dev/null

if [ $? = 0 ]; then
    echo 'No update since last scraping'

    cat ../hashes/omonimi.json.sha512 2>/dev/null | sha512sum --check 2>/dev/null
    if [ $? = 0 ]; then
        echo 'omonimi.json has not changed'

        if [ "$1" = 'workflow_dispatch' ] || [ "$GITHUB_ACTIONS" != "true" ]; then
            echo 'Scraping anyway due to manual dispatch'
        else
            echo 'Exiting now'
            exit 0
        fi
    fi
fi

echo 'Install python requirements'
pip install -r requirements.txt

echo 'Starting step 1'
python3 step1.py

if [ $? -ne 0 ]; then
    echo 'Error during execution of step 1'
    exit 1
fi

echo 'Clearing empty lines from orario.txt'
awk 'NF { $1=$1; print }' orario.txt > tmp.txt
mv tmp.txt orario.txt

echo 'Starting step 2'
php step2.php

if [ $? -ne 0 ]; then
    echo 'Error during execution of step 2'
    exit 1
fi

echo 'Starting step3'
php step3.php

echo 'Encrypt abbreviations'
gpg --trust-model always --output abbreviations_matches.json.gpg --encrypt --recipient itispaleocapa-orario-scraper@cristianlivella.com abbreviations_matches.json
rm abbreviations_matches.json

echo 'Saving new PDF hash'
echo $new_hash > ../hashes/orario.pdf.sha512

echo 'Saving new omonimi.json hash'
sha512sum omonimi.json > ../hashes/omonimi.json.sha512

echo 'Removing temp files...'
rm orario.pdf
rm orario.txt
rm ore_classi.txt
rm ore_inizio.txt

echo 'Moving files to root...'
mv orario.json ../orario.json
mv orarioV2.json ../orarioV2.json
mv orario_bgschoolbot.json ../orario_bgschoolbot.json
mv abbreviations_matches.json.gpg ../abbreviations_matches.json.gpg
mv abbreviations_unmatched.json ../abbreviations_unmatched.json
mv omonimi.json ../config/omonimi.json

if [ "$GITHUB_ACTIONS" == "true" ]; then
    echo 'Commit to repo'
    git add ../*
    git commit -m "🍱 $name"
    touch bgschoolbot-need-update
fi
