package com.pax.dal;

import android.graphics.Bitmap;
import com.pax.dal.entity.EFontTypeAscii;
import com.pax.dal.entity.EFontTypeExtCode;
import com.pax.dal.exceptions.PrinterDevException;

public interface IPrinter {
    void init() throws PrinterDevException;
    int getStatus() throws PrinterDevException;
    void fontSet(EFontTypeAscii ascii, EFontTypeExtCode cfont) throws PrinterDevException;
    void spaceSet(byte wordSpace, byte lineSpace) throws PrinterDevException;
    void printStr(String str, String charset) throws PrinterDevException;
    void printBitmap(Bitmap bitmap) throws PrinterDevException;
    void step(int b) throws PrinterDevException;
    void setGray(int level) throws PrinterDevException;
    int start() throws PrinterDevException;
}
