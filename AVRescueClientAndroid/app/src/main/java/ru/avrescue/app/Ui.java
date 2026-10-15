package ru.avrescue.app;

import android.graphics.Color;
import android.graphics.Typeface;
import android.os.Build;
import android.util.TypedValue;
import android.view.Gravity;
import android.view.ViewGroup;
import android.widget.*;
import android.content.Context;

public final class Ui {
 public static final int BG=Color.rgb(8,9,10), PANEL=Color.rgb(17,19,23), RED=Color.rgb(237,17,28), WHITE=Color.WHITE, MUTED=Color.rgb(174,179,186), GREEN=Color.rgb(53,208,127);

 public static TextView text(Context c,String s,int sp,boolean bold){
   TextView v=new TextView(c);v.setText(s);v.setTextColor(WHITE);v.setTextSize(TypedValue.COMPLEX_UNIT_SP,sp);
   v.setMaxLines(Integer.MAX_VALUE);v.setHorizontallyScrolling(false);v.setIncludeFontPadding(true);
   if(Build.VERSION.SDK_INT>=23){v.setBreakStrategy(android.text.Layout.BREAK_STRATEGY_HIGH_QUALITY);v.setHyphenationFrequency(android.text.Layout.HYPHENATION_FREQUENCY_NORMAL);}
   if(bold)v.setTypeface(Typeface.DEFAULT,Typeface.BOLD);v.setPadding(0,dp(c,4),0,dp(c,4));return v;
 }
 public static TextView muted(Context c,String s,int sp){TextView v=text(c,s,sp,false);v.setTextColor(MUTED);return v;}
 public static EditText input(Context c,String hint){
   EditText e=new EditText(c);e.setHint(hint);e.setHintTextColor(Color.GRAY);e.setTextColor(WHITE);
   e.setBackgroundResource(R.drawable.input);e.setPadding(dp(c,14),dp(c,12),dp(c,14),dp(c,12));
   LinearLayout.LayoutParams p=new LinearLayout.LayoutParams(-1,-2);p.setMargins(0,dp(c,7),0,dp(c,7));e.setLayoutParams(p);return e;
 }
 public static Button button(Context c,String s){
   Button b=new Button(c);b.setText(s);b.setTextColor(WHITE);b.setAllCaps(false);b.setTypeface(Typeface.DEFAULT,Typeface.BOLD);
   b.setBackgroundResource(R.drawable.button_red);LinearLayout.LayoutParams p=new LinearLayout.LayoutParams(-1,-2);p.setMargins(0,dp(c,7),0,dp(c,7));b.setLayoutParams(p);return b;
 }
 public static Button darkButton(Context c,String s){Button b=button(c,s);b.setBackgroundResource(R.drawable.button_dark);return b;}
 public static LinearLayout column(Context c){LinearLayout l=new LinearLayout(c);l.setOrientation(LinearLayout.VERTICAL);int widthDp=(int)(c.getResources().getDisplayMetrics().widthPixels/c.getResources().getDisplayMetrics().density);int side=widthDp<360?14:widthDp>=600?32:20;l.setPadding(dp(c,side),dp(c,16),dp(c,side),dp(c,24));l.setBackgroundColor(BG);l.setLayoutParams(new ViewGroup.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT,ViewGroup.LayoutParams.WRAP_CONTENT));return l;}
 public static LinearLayout card(Context c){LinearLayout l=new LinearLayout(c);l.setOrientation(LinearLayout.VERTICAL);l.setPadding(dp(c,16),dp(c,14),dp(c,16),dp(c,14));l.setBackgroundResource(R.drawable.panel);LinearLayout.LayoutParams p=new LinearLayout.LayoutParams(-1,-2);p.setMargins(0,dp(c,7),0,dp(c,7));l.setLayoutParams(p);return l;}
 public static TextView brand(Context c){TextView v=text(c,"AVR  Rescue",30,true);v.setSingleLine(true);if(Build.VERSION.SDK_INT>=26)v.setAutoSizeTextTypeUniformWithConfiguration(22,30,1,TypedValue.COMPLEX_UNIT_SP);return v;}
 public static TextView chip(Context c,String s,boolean good){TextView v=text(c,s,12,true);v.setTextColor(good?GREEN:MUTED);return v;}
 public static Button navButton(Context c,String s){Button b=new Button(c);b.setText(s);b.setAllCaps(false);b.setTextColor(WHITE);b.setTextSize(TypedValue.COMPLEX_UNIT_SP,11);b.setMinWidth(0);b.setMinimumWidth(0);b.setMinHeight(0);b.setMinimumHeight(0);b.setGravity(Gravity.CENTER);b.setMaxLines(2);b.setPadding(dp(c,3),0,dp(c,3),0);b.setBackgroundColor(Color.TRANSPARENT);return b;}
 public static int dp(Context c,int n){return (int)(n*c.getResources().getDisplayMetrics().density+.5f);}
}
