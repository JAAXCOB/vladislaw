package ru.avrescue.app;

import android.content.Context;
import android.content.res.ColorStateList;
import android.graphics.Color;
import android.graphics.Typeface;
import android.graphics.drawable.GradientDrawable;
import android.os.Build;
import android.text.Spannable;
import android.text.SpannableString;
import android.text.style.ForegroundColorSpan;
import android.util.TypedValue;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.*;

public final class Ui {
 public static final int BG=Color.rgb(7,8,10), PANEL=Color.rgb(18,20,24), PANEL_ALT=Color.rgb(25,28,34), RED=Color.rgb(239,27,36), WHITE=Color.WHITE, MUTED=Color.rgb(166,172,182), GREEN=Color.rgb(58,211,137), BLUE=Color.rgb(67,138,245), LINE=Color.rgb(48,53,62);
 private static Typeface medium(){return Typeface.create("sans-serif-medium",Typeface.NORMAL);}
 private static GradientDrawable shape(int color,float radius){GradientDrawable d=new GradientDrawable();d.setColor(color);d.setCornerRadius(radius);return d;}
 private static GradientDrawable outlined(int fill,int stroke,float radius){GradientDrawable d=shape(fill,radius);d.setStroke(1,stroke);return d;}
 public static TextView text(Context c,String s,int sp,boolean bold){TextView v=new TextView(c);v.setText(s);v.setTextColor(WHITE);v.setTextSize(TypedValue.COMPLEX_UNIT_SP,sp);v.setLineSpacing(0,1.08f);v.setMaxLines(Integer.MAX_VALUE);v.setHorizontallyScrolling(false);v.setIncludeFontPadding(true);if(Build.VERSION.SDK_INT>=23){v.setBreakStrategy(android.text.Layout.BREAK_STRATEGY_HIGH_QUALITY);v.setHyphenationFrequency(android.text.Layout.HYPHENATION_FREQUENCY_NORMAL);}if(bold)v.setTypeface(medium());v.setPadding(0,dp(c,4),0,dp(c,4));return v;}
 public static TextView muted(Context c,String s,int sp){TextView v=text(c,s,sp,false);v.setTextColor(MUTED);return v;}
 public static EditText input(Context c,String hint){EditText e=new EditText(c);e.setHint(hint);e.setHintTextColor(Color.rgb(112,119,130));e.setTextColor(WHITE);e.setTextSize(TypedValue.COMPLEX_UNIT_SP,16);e.setSingleLine(false);e.setMinHeight(dp(c,56));e.setBackground(outlined(Color.rgb(13,15,18),LINE,dp(c,14)));e.setPadding(dp(c,16),dp(c,13),dp(c,16),dp(c,13));if(Build.VERSION.SDK_INT>=21)e.setBackgroundTintList(null);LinearLayout.LayoutParams p=new LinearLayout.LayoutParams(-1,-2);p.setMargins(0,dp(c,6),0,dp(c,6));e.setLayoutParams(p);return e;}
 public static Button button(Context c,String s){Button b=new Button(c);b.setText(s);b.setTextColor(WHITE);b.setTextSize(TypedValue.COMPLEX_UNIT_SP,15);b.setAllCaps(false);b.setTypeface(medium());b.setGravity(Gravity.CENTER);b.setMinHeight(dp(c,54));b.setMinimumHeight(dp(c,54));b.setBackground(shape(RED,dp(c,14)));if(Build.VERSION.SDK_INT>=21)b.setBackgroundTintList(ColorStateList.valueOf(RED));b.setStateListAnimator(null);b.setElevation(dp(c,2));LinearLayout.LayoutParams p=new LinearLayout.LayoutParams(-1,-2);p.setMargins(0,dp(c,7),0,dp(c,7));b.setLayoutParams(p);return b;}
 public static Button darkButton(Context c,String s){Button b=button(c,s);b.setBackground(outlined(PANEL_ALT,LINE,dp(c,14)));if(Build.VERSION.SDK_INT>=21)b.setBackgroundTintList(null);b.setElevation(0);return b;}
 public static Button primaryButton(Context c,String s){Button b=button(c,s);b.setTextSize(TypedValue.COMPLEX_UNIT_SP,17);b.setMinHeight(dp(c,58));b.setMinimumHeight(dp(c,58));return b;}
 public static Button tabButton(Context c,String s,boolean selected,Runnable action){Button b=selected?button(c,s):darkButton(c,s);b.setTextSize(TypedValue.COMPLEX_UNIT_SP,12);b.setMinHeight(dp(c,48));b.setMinimumHeight(dp(c,48));b.setOnClickListener(v->action.run());return b;}
 public static LinearLayout column(Context c){LinearLayout l=new LinearLayout(c);l.setOrientation(LinearLayout.VERTICAL);int widthDp=(int)(c.getResources().getDisplayMetrics().widthPixels/c.getResources().getDisplayMetrics().density);int side=widthDp<360?14:widthDp>=600?32:20;l.setPadding(dp(c,side),dp(c,18),dp(c,side),dp(c,30));l.setBackgroundColor(BG);l.setLayoutParams(new ViewGroup.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT,ViewGroup.LayoutParams.WRAP_CONTENT));return l;}
 public static LinearLayout card(Context c){LinearLayout l=new LinearLayout(c);l.setOrientation(LinearLayout.VERTICAL);l.setPadding(dp(c,18),dp(c,16),dp(c,18),dp(c,16));l.setBackground(outlined(PANEL,LINE,dp(c,18)));l.setElevation(dp(c,1));LinearLayout.LayoutParams p=new LinearLayout.LayoutParams(-1,-2);p.setMargins(0,dp(c,7),0,dp(c,7));l.setLayoutParams(p);return l;}
 public static TextView brand(Context c){TextView v=text(c,"AVR  RESCUE",28,true);SpannableString ss=new SpannableString("AVR  RESCUE");ss.setSpan(new ForegroundColorSpan(RED),2,3,Spannable.SPAN_EXCLUSIVE_EXCLUSIVE);v.setText(ss);v.setLetterSpacing(.015f);v.setSingleLine(true);return v;}
 public static TextView chip(Context c,String s,boolean good){TextView v=text(c,s,12,true);v.setTextColor(good?GREEN:MUTED);v.setBackground(outlined(good?Color.rgb(15,42,31):PANEL_ALT,good?Color.rgb(38,111,78):LINE,dp(c,99)));v.setPadding(dp(c,10),dp(c,5),dp(c,10),dp(c,5));LinearLayout.LayoutParams p=new LinearLayout.LayoutParams(-2,-2);p.setMargins(0,dp(c,4),0,dp(c,8));v.setLayoutParams(p);return v;}
 public static LinearLayout routePoint(Context c,String point,String address,boolean destination){LinearLayout l=new LinearLayout(c);l.setOrientation(LinearLayout.VERTICAL);l.setPadding(dp(c,14),dp(c,12),dp(c,14),dp(c,12));int accent=destination?GREEN:BLUE;l.setBackground(outlined(PANEL_ALT,accent,dp(c,14)));LinearLayout.LayoutParams p=new LinearLayout.LayoutParams(-1,-2);p.setMargins(0,dp(c,6),0,dp(c,6));l.setLayoutParams(p);TextView label=text(c,(destination?"Б  ":"А  ")+point,13,true);label.setTextColor(accent);l.addView(label);l.addView(text(c,address==null||address.trim().isEmpty()?"Адрес не указан":address.trim(),17,true));return l;}
 public static LinearLayout workProgress(Context c,String status){String[] codes={"assigned","enroute","arrived","loading","in_transit","done"};String[] labels={"Заявка принята","Маршрут к точке А","На месте","Погрузка","Маршрут к точке Б","Завершено"};int current=0;for(int i=0;i<codes.length;i++)if(codes[i].equals(status)||("completed".equals(status)&&"done".equals(codes[i])))current=i;LinearLayout l=card(c);l.addView(text(c,"Этапы работы",18,true));for(int i=0;i<labels.length;i++){TextView step=text(c,(i<current?"✓  ":i==current?"●  ":"○  ")+labels[i],14,i==current);step.setTextColor(i<current?GREEN:i==current?WHITE:MUTED);l.addView(step);}return l;}
 public static Button navButton(Context c,String s,boolean selected){Button b=new Button(c);b.setText(s);b.setAllCaps(false);b.setTextColor(selected?WHITE:MUTED);b.setTextSize(TypedValue.COMPLEX_UNIT_SP,11);b.setTypeface(selected?medium():Typeface.DEFAULT);b.setMinWidth(0);b.setMinimumWidth(0);b.setMinHeight(0);b.setMinimumHeight(0);b.setGravity(Gravity.CENTER);b.setMaxLines(2);b.setPadding(dp(c,3),0,dp(c,3),0);b.setBackground(selected?shape(Color.rgb(43,25,29),dp(c,12)):shape(Color.TRANSPARENT,0));if(Build.VERSION.SDK_INT>=21)b.setBackgroundTintList(null);return b;}
 public static View divider(Context c){View v=new View(c);v.setBackgroundColor(LINE);v.setLayoutParams(new LinearLayout.LayoutParams(-1,dp(c,1)));return v;}
 public static int dp(Context c,int n){return (int)(n*c.getResources().getDisplayMetrics().density+.5f);}
}
