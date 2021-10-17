echo 'Starting scraping'

html=$(wget --header "Cookie: pasw_law_cookie=yes" -qO - https://www.itispaleocapa.edu.it/orario-classi/)

name=$(echo "$html" | grep -o -P '(?<=\<h2 class\="posttitle"\>).*(?=\<\/h2\>)')
pdf_url=$(echo "$html" | grep -o -P '(?<=docs\.google\.com\/viewer\?url\=).*(?<=\.pdf)' | sed --expression='s@+@ @g;s@%@\\x@g' | xargs -0 printf "%b")

wget -qO 'orario.pdf' $pdf_url

if [ $? -ne 0 ]; then
    echo 'Unable to get the PDF file'
    exit 1
fi

last_hash=$(cat last_hash.txt 2>/dev/null)
new_hash=$(sha512sum orario.pdf)

cat last_hash.txt 2>/dev/null | sha512sum --check 2>/dev/null

if [ $? = 0 ]; then
    echo 'No update since last scraping'
    exit 0
fi

echo 'Starting step 1'
python3 step1.py

if [ $? -ne 0 ]; then
    echo 'Error during execution of step 1'
    exit 1
fi

echo 'Clearing empty lines from orario.txt'
awk 'NF { $1=$1; print }' orario.txt > tmp.txt
mv tmp.txt orario.txt

echo 'Stating step 2'
php step2.php

if [ $? -ne 0 ]; then
    echo 'Error during execution of step 2'
    exit 1
fi

echo 'Saving new PDF hash'
echo $new_hash > last_hash.txt

echo 'Removing temp files...'
rm orario.pdf
rm orario.txt
rm ore_classi.txt
rm ore_inizio.txt

echo 'Commit to repo'
git commit -m "🍱 $name"
