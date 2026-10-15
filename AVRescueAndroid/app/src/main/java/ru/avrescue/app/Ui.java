package ru.avrescue.app;

import android.graphics.Color;
import android.graphics.Typeface;
import android.widget.*;
import android.content.Context;

public final class Ui {
 public static final int BG=Color.rgb(8,9,10), PANEL=Color.rgb(17,19,23), RED=Color.rgb(237,17,28), WHITE=Color.WHITE, MUTED=Color.rgb(174,179,186), GREEN=Color.rgb(53,208,127);

 public static TextView text(Context c,String s,int sp,boolean bold){
   TextView v=new TextView(c); v.setText(s); v.setTextColor(WHITE); v.setTextSize(sp);
   if(bold)v.setTypeface(Typeface.DEFAULT,Typeface.BOLD); v.setPadding(0,8,0,8); return v;
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
 public static LinearLayout column(Context c){LinearLayout l=new LinearLayout(c);l.setOrientation(LinearLayout.VERTICAL);l.setPadding(dp(c,20),dp(c,16),dp(c,20),dp(c,24));l.setBackgroundColor(BG);return l;}
 public static LinearLayout card(Context c){LinearLayout l=new LinearLayout(c);l.setOrientation(LinearLayout.VERTICAL);l.setPadding(dp(c,16),dp(c,14),dp(c,16),dp(c,14));l.setBackgroundResource(R.drawable.panel);LinearLayout.LayoutParams p=new LinearLayout.LayoutParams(-1,-2);p.setMargins(0,dp(c,7),0,dp(c,7));l.setLayoutParams(p);return l;}
 public static TextView brand(Context c){return text(c,"AVR  Rescue",30,true);}
 public static TextView chip(Context c,String s,boolean good){TextView v=text(c,s,12,true);v.setTextColor(good?GREEN:MUTED);return v;}
 public static int dp(Context c,int n){return (int)(n*c.getResources().getDisplayMetrics().density+.5f);}
}