#!/usr/bin/env python3
# -*- coding: utf-8 -*

# ITIS Paleocapa Orario Scraping Tool
# Created by Cristian Livella, mainly used by BgSchoolBot

# This script does the first step of the scraping process.
# It convert each page of the timetable into an image,
# and cuts the cells of each hour, to see if it is a lesson hour
# or an empty hour, based on the number of black pixels in the cell.

# It produces 3 files, used by other scripts to complete the job:
# - orario.txt: is the text conteined in the PDF file.
# - ore_classi.txt: contains the hours of lessons for each day of each class;
#   each row is a class and each day is separated by a dot.
# - ore_inizio.txt:

import os
import pytesseract
import re
import shutil
from pathlib import Path
from pdf2image import convert_from_path
from PIL import Image
from tika import parser

# Margin of the table
MARGIN_TOP = 145
MARGIN_LEFT = 85

# Size of each cell
CELL_HEIGHT = 110
CELL_WIDTH = 112

# We ignore a few pixels to the right and below each cell,
# to prevent the black line separating the cells from causing false positives.
# (the margins and size defined above are not 100% accurate,
# the actual size may vary from time to time)
TRIM_HEIGHT = 40
TRIM_WIDTH = 20

# Just for debug purpose.
# If set to True it saves all the cropped pieces in the directories
# out/black and out/white, based on the classification made.
SAVE_PIECES_IMG = False

if SAVE_PIECES_IMG:
    # Delete the out directory to make sure it is empty,
    # then recreate the black and white folders.
    shutil.rmtree('out/', ignore_errors=True)
    Path('out/black').mkdir(parents=True, exist_ok=True)
    Path('out/white').mkdir(parents=True, exist_ok=True)

# open the files
orario = open('orario.txt', 'wb')
oreClassi = open('ore_classi.txt', 'wb')
oreInizio = open('ore_inizio.txt', 'wb')

# extract the text from the PDF and put in the orario.txt file
raw = parser.from_file('orario.pdf')
text = raw['content']
orario.write(text.encode())

# extract the class names
classes = re.findall('[0-9]{1}[TIME]{1}[A-Za-z]{1,4}', text)

# convert the PDF pages to images
pages = convert_from_path('orario.pdf', 100)

# this is used to count the saved image piaces, only if SAVE_PIECES_IMG = True
pieceCount = 0

for index, page in enumerate(pages):
    # lessons are from Monday to Friday
    for day in range(0, 6):
        lessonCount = 0
        lastLessonTime = 0
        emptyInitialHours = 0

        # q = 5th hour; w = 6th hour
        lunchBreakTime = ''

        # there are maximum 8 hours of lesson per day
        for time in range(0, 8):
            # extract the current lesson cell from the PDF page
            piece = page.crop((
                MARGIN_LEFT + day * CELL_WIDTH,
                MARGIN_TOP + time * CELL_HEIGHT,
                MARGIN_LEFT + (day + 1) * CELL_WIDTH - TRIM_WIDTH,
                MARGIN_TOP + (time + 1) * CELL_HEIGHT - TRIM_HEIGHT,
            ))

            # convert the image to black and white (1-bit pixels)
            piece = piece.convert('1')

            # initialize black pixel counters
            blackPixels = 0

            # count black pixels
            for pixel in piece.getdata():
                if pixel == 0:
                    blackPixels += 1

            if blackPixels > 50 and lastLessonTime == 0:
                # a lot of black pixels means that there's a lesson
                lessonCount += 1
                if SAVE_PIECES_IMG:
                    piece.save('out/black/' + str(pieceCount) + '.jpg')
                    pieceCount += 1
            elif blackPixels > 50 and lastLessonTime > 0:
                # if the last lesson of the day has already been found,
                # there should be no more cells with many black pixels
                print('Class ' + classes[index] + ' needs manual check!')
                sys.exit(1)
            elif lessonCount == 0:
                # if the first lesson of the day has not yet been found
                # and the cell is empty, the count of empty hours
                # at the beginning of the day is incremented
                emptyInitialHours = emptyInitialHours + 1
                if SAVE_PIECES_IMG:
                    piece.save('out/white/' + str(pieceCount) + '.jpg')
                    pieceCount += 1
            elif time == 4:
                # if the 5th hours is empty, then it's lunch break
                # (time is 0-based)
                lunchBreakTime = 'q'
            elif time == 5:
                # if the 6th hours is empty, then it's lunch break
                # (time is 0-based)
                lunchBreakTime = 'w'
            elif lastLessonTime == 0:
                lastLessonTime = time

            # if there are no lessons after the lunch break,
            # it means that the lunch break does not exist today,
            # and students are already at home :)
            if (lastLessonTime == 5 and lunchBreakTime == 'q') or (lastLessonTime == 6 and lunchBreakTime == 'w'):
                lunchBreakTime = ''

        # save the count of today lessons to the file
        oreClassi.write((str(lessonCount) + '.').encode())

        if emptyInitialHours == 0 and lunchBreakTime != '':
            # if classes start in the first hour and there is a lunch break,
            # save only the lunch break time
            oreInizio.write((lunchBreakTime + '.').encode())
        else:
            # otherwise save the initial empty hours and the hour of the lunch break
            oreInizio.write((str(emptyInitialHours) + lunchBreakTime + '.').encode())

    # remove the trailing dots and add newlines
    oreClassi.seek(-1, os.SEEK_END)
    oreClassi.truncate()
    oreClassi.write('\n'.encode())
    oreInizio.seek(-1, os.SEEK_END)
    oreInizio.truncate()
    oreInizio.write('\n'.encode())

print('Step 1 completed!')
